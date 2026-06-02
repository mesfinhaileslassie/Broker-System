<?php
// user/transaction.php - Complete Transaction Page with Escrow and Seller Notifications
// UPDATED: Dynamic labels for Buyer/Seller based on transaction type

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/escrow_functions.php';
require_once '../includes/transaction_workflow.php';

requireLogin();

$page_title = 'Transaction Details';
ob_start();

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];
$transaction_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$message = '';
$error = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'mark_delivered') {
        $delivery_notes = $_POST['delivery_notes'] ?? '';
        $result = markDelivery($conn, $transaction_id, $user_id, $delivery_notes);
        if ($result['success']) {
            $message = "✓ Delivery marked successfully! Waiting for buyer confirmation.";
        } else {
            $error = $result['error'];
        }
    }
    
    if ($action === 'confirm_receipt') {
        $confirm_notes = $_POST['confirm_notes'] ?? '';
        $result = confirmReceiptAndRelease($conn, $transaction_id, $user_id, $confirm_notes);
        if ($result['success']) {
            $message = "✓ Delivery confirmed! You can now pay the remaining balance.";
        } else {
            $error = $result['error'];
        }
    }
    
    if ($action === 'raise_dispute') {
        $result = openTransactionDispute($conn, $transaction_id, $user_id, $_POST['dispute_reason'] ?? '');
        if ($result['success']) {
            $message = $result['message'];
        } else {
            $error = $result['error'];
        }
    }
}

// Get transaction details
$transaction = $conn->query("
    SELECT t.*, l.title as listing_title, l.type as listing_type, l.cover_image,
           l.admin_deposit_percent, l.admin_commission_percent,
           u1.full_name as buyer_name, u1.email as buyer_email, u1.phone as buyer_phone,
           u2.full_name as seller_name, u2.email as seller_email, u2.phone as seller_phone
    FROM transactions t
    JOIN listings l ON t.listing_id = l.id
    JOIN users u1 ON t.buyer_id = u1.id
    JOIN users u2 ON t.seller_id = u2.id
    WHERE t.id = $transaction_id AND (t.buyer_id = $user_id OR t.seller_id = $user_id)
")->fetch_assoc();

if (!$transaction) {
    $conn->close();
    header('Location: dashboard.php');
    exit;
}

$workflow = getTransactionWorkflowView($conn, $transaction_id);
if ($workflow) {
    $transaction = array_merge($transaction, $workflow);
}

// Check if this is a job application
$is_job_application = ($transaction['listing_type'] == 'job');

// Get payment totals
$buyerDepositPaid = (float) ($conn->query("
    SELECT COALESCE(SUM(amount), 0) AS total FROM payments
    WHERE transaction_id = $transaction_id AND type IN ('deposit_buyer', 'service_fee', 'commission') AND status = 'confirmed'
")->fetch_assoc()['total'] ?? 0);

$remainingPaid = (float) ($conn->query("
    SELECT COALESCE(SUM(amount), 0) AS total FROM payments
    WHERE transaction_id = $transaction_id AND type = 'remaining_balance' AND status = 'confirmed'
")->fetch_assoc()['total'] ?? 0);

$totalPaid = $buyerDepositPaid + $remainingPaid;
$totalAmount = floatval($transaction['total_amount']);
$remainingBalance = max(0, $totalAmount - $buyerDepositPaid - $remainingPaid);

// Get payment history
$payments_list = $conn->query("
    SELECT * FROM payments 
    WHERE transaction_id = $transaction_id AND status = 'confirmed' 
    ORDER BY created_at DESC
");

$is_buyer = ($transaction['buyer_id'] == $user_id);
$is_seller = ($transaction['seller_id'] == $user_id);

// Check delivery confirmation status
$seller_confirmed = ($transaction['seller_delivery_confirmed'] == 1);
$buyer_confirmed = ($transaction['buyer_delivery_confirmed'] == 1);
$both_confirmed = ($seller_confirmed && $buyer_confirmed);

// Determine if remaining payment is available
$can_pay_remaining = ($is_buyer && $both_confirmed && $remainingBalance > 0 && $remainingPaid == 0 && !$is_job_application);

// Calculate amounts
$depositPercent = $transaction['admin_deposit_percent'] ?? 30;
$commissionPercent = $transaction['admin_commission_percent'] ?? 15;
$depositAmount = $totalAmount * ($depositPercent / 100);
$commissionAmount = $totalAmount * ($commissionPercent / 100);

// For job applications, determine if service fee is paid
$service_fee_paid = ($buyerDepositPaid > 0);
$application_submitted = ($service_fee_paid && $transaction['status'] != 'completed');

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transaction #<?php echo $transaction['id']; ?> - Ethio Brokerplace</title>
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
            --info: #3b82f6;
            --dark: #1e293b;
            --gray: #64748b;
            --light: #f8fafc;
            --border: #e2e8f0;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; }
        
        .transaction-container { max-width: 1200px; margin: 0 auto; padding: 0 20px; }
        
        /* Header */
        .transaction-header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 32px;
            padding: 32px;
            margin-bottom: 28px;
            color: white;
            position: relative;
            overflow: hidden;
        }
        
        .transaction-header::before {
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
        
        .transaction-header h1 {
            position: relative;
            z-index: 1;
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        
        .transaction-header p {
            position: relative;
            z-index: 1;
            font-size: 14px;
            opacity: 0.9;
        }
        
        /* Cards */
        .card {
            background: white;
            border-radius: 24px;
            padding: 28px;
            margin-bottom: 24px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            border: 1px solid var(--border);
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 16px;
            border-bottom: 2px solid var(--border);
            flex-wrap: wrap;
            gap: 12px;
        }
        
        .card-header h3 {
            font-size: 18px;
            font-weight: 700;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .status-badge {
            display: inline-block;
            padding: 6px 16px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-active { background: #dbeafe; color: #1e40af; }
        .status-delivered { background: #fed7aa; color: #9a3412; }
        .status-completed { background: #d1fae5; color: #059669; }
        .status-disputed { background: #fee2e2; color: #dc2626; }
        .status-pending { background: #fef3c7; color: #92400e; }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 0;
        }
        
        .info-item {
            padding: 16px;
            background: var(--light);
            border-radius: 20px;
            text-align: center;
        }
        
        .info-label {
            font-size: 11px;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }
        
        .info-value {
            font-size: 20px;
            font-weight: 800;
            color: var(--dark);
        }
        
        /* Delivery Cards */
        .delivery-card {
            background: var(--light);
            border-radius: 20px;
            padding: 20px;
            margin-bottom: 20px;
            border-left: 4px solid var(--primary);
        }
        
        .delivery-card.confirmed {
            background: #d1fae5;
            border-left-color: var(--success);
        }
        
        .delivery-card h4 {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        /* Application Card for Jobs */
        .application-card {
            background: linear-gradient(135deg, #dbeafe, #e0e7ff);
            border-radius: 20px;
            padding: 24px;
            margin-bottom: 24px;
            border: 2px solid var(--primary);
            text-align: center;
        }
        
        .application-icon {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
        }
        
        .application-icon i { font-size: 32px; color: white; }
        
        .application-status {
            font-size: 20px;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 8px;
        }
        
        .application-message {
            color: var(--gray);
            font-size: 14px;
        }
        
        /* Party Cards */
        .party-card {
            background: white;
            border-radius: 24px;
            padding: 24px;
            margin-bottom: 0;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            border: 1px solid var(--border);
            height: 100%;
        }
        
        .party-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid var(--border);
        }
        
        .party-icon {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .party-icon i { font-size: 24px; color: white; }
        
        .party-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--dark);
        }
        
        .party-detail {
            margin-bottom: 16px;
            padding: 12px;
            background: var(--light);
            border-radius: 16px;
        }
        
        .party-label {
            font-size: 11px;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }
        
        .party-value {
            font-size: 15px;
            font-weight: 600;
            color: var(--dark);
            word-break: break-word;
        }
        
        /* Buttons */
        .btn-group { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 20px; }
        .btn {
            padding: 12px 28px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }
        .btn-primary { background: linear-gradient(135deg, var(--primary), var(--secondary)); color: white; }
        .btn-success { background: var(--success); color: white; }
        .btn-warning { background: var(--warning); color: white; }
        .btn-danger { background: var(--danger); color: white; }
        .btn-outline { background: transparent; border: 2px solid var(--border); color: var(--gray); }
        .btn-outline:hover { border-color: var(--primary); color: var(--primary); }
        .btn:hover { transform: translateY(-3px); box-shadow: 0 8px 20px rgba(0,0,0,0.15); }
        
        /* Alerts */
        .alert {
            padding: 16px 20px;
            border-radius: 20px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .alert-success { background: #d1fae5; color: #059669; border-left: 4px solid #059669; }
        .alert-error { background: #fee2e2; color: #dc2626; border-left: 4px solid #dc2626; }
        .alert-info { background: #dbeafe; color: #1e40af; border-left: 4px solid #1e40af; }
        .alert-warning { background: #fed7aa; color: #9a3412; border-left: 4px solid #f59e0b; }
        
        /* Payment Box */
        .remaining-payment-box {
            background: linear-gradient(135deg, #ecfdf5, #d1fae5);
            border-radius: 20px;
            padding: 24px;
            text-align: center;
            border: 2px solid var(--success);
            margin-top: 20px;
        }
        
        .remaining-amount {
            font-size: 32px;
            font-weight: 800;
            color: #059669;
            margin: 10px 0;
        }
        
        /* Table */
        .payment-table {
            width: 100%;
            border-collapse: collapse;
        }
        .payment-table th, .payment-table td {
            padding: 14px 12px;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }
        .payment-table th {
            font-weight: 600;
            color: var(--gray);
            background: var(--light);
            font-size: 12px;
        }
        
        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }
        .modal-content {
            background: white;
            border-radius: 28px;
            padding: 32px;
            width: 500px;
            max-width: 90%;
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 600; }
        .form-group input, .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--border);
            border-radius: 12px;
        }
        
        /* Two Column Layout */
        .two-columns {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-bottom: 24px;
        }
        
        @media (max-width: 900px) {
            .info-grid { grid-template-columns: repeat(2, 1fr); }
            .two-columns { grid-template-columns: 1fr; }
        }
        
        @media (max-width: 640px) {
            .info-grid { grid-template-columns: 1fr; }
            .btn-group { flex-direction: column; }
            .btn { justify-content: center; }
            .transaction-header h1 { font-size: 22px; }
            .card { padding: 20px; }
        }
    </style>
</head>
<body>

<div class="transaction-container">
    <!-- Header -->
    <div class="transaction-header">
        <h1><i class="fas fa-receipt"></i> Transaction #<?php echo $transaction['id']; ?></h1>
        <p><?php echo htmlspecialchars($transaction['listing_title']); ?></p>
    </div>
    
    <?php if ($message): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i>
            <div><?php echo $message; ?></div>
        </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i>
            <div><?php echo $error; ?></div>
        </div>
    <?php endif; ?>
    
    <!-- Status Overview -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-chart-line"></i> Status Overview</h3>
            <span class="status-badge <?php 
                if ($is_job_application) {
                    if ($transaction['status'] == 'completed') echo 'status-completed';
                    elseif ($service_fee_paid) echo 'status-active';
                    else echo 'status-pending';
                } else {
                    if ($remainingPaid > 0) echo 'status-completed';
                    elseif ($both_confirmed) echo 'status-active';
                    elseif ($seller_confirmed) echo 'status-delivered';
                    elseif ($buyerDepositPaid > 0) echo 'status-active';
                    else echo 'status-pending';
                }
            ?>">
                <?php 
                if ($is_job_application) {
                    if ($transaction['status'] == 'completed') {
                        echo '✓ Application Complete';
                    } elseif ($service_fee_paid) {
                        echo '📋 Application Submitted - Pending Review';
                    } else {
                        echo '⏳ Awaiting Payment';
                    }
                } else {
                    if ($remainingPaid > 0) echo '✓ Fully Paid';
                    elseif ($both_confirmed) echo '✓ Delivery Confirmed - Ready for Payment';
                    elseif ($seller_confirmed) echo '📦 Delivered - Awaiting Your Confirmation';
                    elseif ($buyerDepositPaid > 0) echo '💰 Deposit Paid - Awaiting Delivery';
                    else echo '📋 Pending Payment';
                }
                ?>
            </span>
        </div>
        
        <div class="info-grid">
            <div class="info-item">
                <div class="info-label"><?php echo $is_job_application ? 'Monthly Salary' : 'Total Price'; ?></div>
                <div class="info-value"><?php echo formatMoney($totalAmount); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label"><?php echo $is_job_application ? 'Service Fee Paid' : 'Deposit Paid'; ?></div>
                <div class="info-value"><?php echo formatMoney($buyerDepositPaid); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Remaining</div>
                <div class="info-value"><?php echo formatMoney($remainingBalance); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Status</div>
                <div class="info-value">
                    <?php 
                    if ($is_job_application) {
                        if ($transaction['status'] == 'completed') {
                            echo '✅ Hired & Completed';
                        } elseif ($service_fee_paid) {
                            echo '📋 Awaiting Employer Review';
                        } else {
                            echo '⏳ Complete Payment';
                        }
                    } else {
                        if ($remainingPaid > 0) echo 'Fully Paid ✓';
                        elseif ($both_confirmed) echo 'Ready for Payment';
                        elseif ($seller_confirmed) echo 'Waiting for Your Confirmation';
                        elseif ($buyerDepositPaid > 0) echo 'Waiting for Seller';
                        else echo 'Awaiting Deposit';
                    }
                    ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Job Application Card (for job applications) -->
    <?php if ($is_job_application && $service_fee_paid && $transaction['status'] != 'completed'): ?>
    <div class="application-card">
        <div class="application-icon">
            <i class="fas fa-file-alt"></i>
        </div>
        <div class="application-status">Application Submitted Successfully!</div>
        <div class="application-message">
            Your application has been submitted. The employer will review your application and contact you if you are selected for an interview.
        </div>
        <div class="btn-group" style="margin-top: 20px; justify-content: center;">
            <a href="jobs.php" class="btn btn-outline">
                <i class="fas fa-search"></i> Browse More Jobs
            </a>
            <a href="chat.php?user=<?php echo $transaction['seller_id']; ?>" class="btn btn-primary">
                <i class="fas fa-comment"></i> Message Employer
            </a>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Delivery Confirmation Section (only for non-job transactions) -->
    <?php if (!$is_job_application && $buyerDepositPaid > 0 && !$remainingPaid): ?>
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-truck"></i> Delivery Status</h3>
        </div>
        
        <div class="delivery-card <?php echo $seller_confirmed ? 'confirmed' : ''; ?>">
            <h4>
                <?php if ($seller_confirmed): ?>
                    <i class="fas fa-check-circle" style="color: #10b981;"></i> ✓ Seller Confirmed Delivery
                <?php else: ?>
                    <i class="fas fa-clock" style="color: #f59e0b;"></i> ⏳ Waiting for Seller to Confirm Delivery
                <?php endif; ?>
            </h4>
            <?php if ($seller_confirmed): ?>
                <p style="color: #065f46;">The seller has marked this item as delivered.</p>
            <?php else: ?>
                <p style="color: #92400e;">The seller will confirm when the item has been delivered.</p>
            <?php endif; ?>
        </div>
        
        <?php if ($is_buyer): ?>
        <div class="delivery-card <?php echo $buyer_confirmed ? 'confirmed' : ''; ?>">
            <h4>
                <?php if ($buyer_confirmed): ?>
                    <i class="fas fa-check-circle" style="color: #10b981;"></i> ✓ You Confirmed Receipt
                <?php else: ?>
                    <i class="fas fa-clock" style="color: #f59e0b;"></i> ⏳ Your Confirmation Needed
                <?php endif; ?>
            </h4>
            <?php if (!$buyer_confirmed && $seller_confirmed): ?>
                <p style="margin-bottom: 16px;">The seller has confirmed delivery. Please confirm that you have received the item.</p>
                <button onclick="openConfirmModal()" class="btn btn-success">
                    <i class="fas fa-check-circle"></i> Confirm Receipt
                </button>
            <?php elseif ($buyer_confirmed): ?>
                <p>You have confirmed receipt of this item.</p>
            <?php elseif (!$seller_confirmed): ?>
                <p>Waiting for seller to confirm delivery before you can confirm.</p>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <?php if ($is_seller && !$seller_confirmed && $buyerDepositPaid > 0): ?>
        <div style="margin-top: 16px;">
            <button onclick="openDeliveryModal()" class="btn btn-primary">
                <i class="fas fa-truck"></i> Mark as Delivered
            </button>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <!-- Remaining Payment Section (only for non-job transactions) -->
    <?php if ($can_pay_remaining): ?>
    <div class="remaining-payment-box">
        <h4><i class="fas fa-wallet"></i> Complete Your Purchase</h4>
        <div class="remaining-amount"><?php echo formatMoney($remainingBalance); ?></div>
        <p>Both you and the seller have confirmed delivery.</p>
        <button onclick="initiateRemainingPayment()" id="payRemainingBtn" class="btn btn-success" style="margin-top: 10px;">
            <i class="fas fa-credit-card"></i> Pay Remaining Balance
        </button>
        <div id="paymentError" style="color: #dc2626; font-size: 13px; margin-top: 12px; display: none;"></div>
    </div>
    <?php endif; ?>
    
    <!-- Party Information - Dynamic Labels -->
    <div class="two-columns">
        <!-- Left Column: Applicant/Buyer -->
        <div class="party-card">
            <div class="party-header">
                <div class="party-icon">
                    <i class="fas <?php echo $is_job_application ? 'fa-user-graduate' : 'fa-user'; ?>"></i>
                </div>
                <div class="party-title">
                    <?php echo $is_job_application ? 'Applicant Information' : 'Buyer Information'; ?>
                </div>
            </div>
            
            <div class="party-detail">
                <div class="party-label">
                    <i class="fas fa-user"></i> <?php echo $is_job_application ? 'Full Name' : 'Name'; ?>
                </div>
                <div class="party-value"><?php echo htmlspecialchars($transaction['buyer_name']); ?></div>
            </div>
            
            <div class="party-detail">
                <div class="party-label">
                    <i class="fas fa-envelope"></i> Email Address
                </div>
                <div class="party-value"><?php echo htmlspecialchars($transaction['buyer_email']); ?></div>
            </div>
            
            <?php if ($transaction['buyer_phone']): ?>
            <div class="party-detail">
                <div class="party-label">
                    <i class="fas fa-phone"></i> Phone Number
                </div>
                <div class="party-value"><?php echo htmlspecialchars($transaction['buyer_phone']); ?></div>
            </div>
            <?php endif; ?>
            
            <div class="btn-group" style="margin-top: 16px;">
                <a href="chat.php?user=<?php echo $transaction['buyer_id']; ?>" class="btn btn-outline">
                    <i class="fas fa-comment"></i> 
                    <?php echo $is_job_application ? 'Message Applicant' : 'Message Buyer'; ?>
                </a>
            </div>
        </div>
        
        <!-- Right Column: Employer/Seller -->
        <div class="party-card">
            <div class="party-header">
                <div class="party-icon">
                    <i class="fas <?php echo $is_job_application ? 'fa-building' : 'fa-store'; ?>"></i>
                </div>
                <div class="party-title">
                    <?php echo $is_job_application ? 'Employer Information' : 'Seller Information'; ?>
                </div>
            </div>
            
            <div class="party-detail">
                <div class="party-label">
                    <i class="fas <?php echo $is_job_application ? 'fa-building' : 'fa-user'; ?>"></i> 
                    <?php echo $is_job_application ? 'Company Name' : 'Name'; ?>
                </div>
                <div class="party-value"><?php echo htmlspecialchars($transaction['seller_name']); ?></div>
            </div>
            
            <div class="party-detail">
                <div class="party-label">
                    <i class="fas fa-envelope"></i> Email Address
                </div>
                <div class="party-value"><?php echo htmlspecialchars($transaction['seller_email']); ?></div>
            </div>
            
            <?php if ($transaction['seller_phone']): ?>
            <div class="party-detail">
                <div class="party-label">
                    <i class="fas fa-phone"></i> Phone Number
                </div>
                <div class="party-value"><?php echo htmlspecialchars($transaction['seller_phone']); ?></div>
            </div>
            <?php endif; ?>
            
            <div class="btn-group" style="margin-top: 16px;">
                <a href="chat.php?user=<?php echo $transaction['seller_id']; ?>" class="btn btn-outline">
                    <i class="fas fa-comment"></i> 
                    <?php echo $is_job_application ? 'Message Employer' : 'Message Seller'; ?>
                </a>
            </div>
        </div>
    </div>
    
    <!-- Payment History -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-history"></i> Payment History</h3>
        </div>
        <?php if ($payments_list && $payments_list->num_rows > 0): ?>
            <table class="payment-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Amount</th>
                        <th>Type</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($p = $payments_list->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo date('M d, H:i', strtotime($p['created_at'])); ?></td>
                            <td><strong><?php echo formatMoney($p['amount']); ?></strong></td>
                            <td><?php 
                                $type_label = ucfirst(str_replace('_', ' ', $p['type']));
                                if ($is_job_application && $p['type'] == 'service_fee') {
                                    $type_label = 'Service Fee';
                                }
                                echo $type_label;
                            ?></td>
                            <td><span class="status-badge" style="background: #d1fae5; color: #059669; padding: 4px 12px;">Confirmed</span></td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p style="text-align: center; padding: 40px; color: var(--gray);">No payments recorded yet.</p>
        <?php endif; ?>
    </div>
</div>

<!-- Delivery Modal -->
<div id="deliveryModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-truck"></i> Mark as Delivered</h3>
            <span onclick="closeDeliveryModal()" style="cursor: pointer; font-size: 24px;">&times;</span>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="mark_delivered">
            <div class="form-group">
                <label>Delivery Notes (Optional)</label>
                <textarea name="delivery_notes" rows="3" placeholder="Add any delivery details or tracking information..."></textarea>
            </div>
            <div class="btn-group">
                <button type="submit" class="btn btn-primary">Confirm Delivery</button>
                <button type="button" onclick="closeDeliveryModal()" class="btn btn-outline">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Confirm Receipt Modal -->
<div id="confirmModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-check-circle"></i> Confirm Receipt</h3>
            <span onclick="closeConfirmModal()" style="cursor: pointer; font-size: 24px;">&times;</span>
        </div>
        <div style="background: #fef3c7; padding: 16px; border-radius: 12px; margin-bottom: 16px;">
            <p><strong>⚠️ Important:</strong> Confirm that you have received the item in good condition.</p>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="confirm_receipt">
            <div class="form-group">
                <label>Confirmation Notes (Optional)</label>
                <textarea name="confirm_notes" rows="3" placeholder="Add any notes about the delivery..."></textarea>
            </div>
            <div class="btn-group">
                <button type="submit" class="btn btn-success">Confirm Receipt</button>
                <button type="button" onclick="closeConfirmModal()" class="btn btn-outline">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Dispute Modal -->
<div id="disputeModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-flag"></i> Raise a Dispute</h3>
            <span onclick="closeDisputeModal()" style="cursor: pointer; font-size: 24px;">&times;</span>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="raise_dispute">
            <div class="form-group">
                <label>Reason for Dispute <span style="color: red;">*</span></label>
                <textarea name="dispute_reason" rows="4" placeholder="Please explain in detail why you're raising this dispute..." required></textarea>
            </div>
            <div class="btn-group">
                <button type="submit" class="btn btn-danger">Submit Dispute</button>
                <button type="button" onclick="closeDisputeModal()" class="btn btn-outline">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function openDeliveryModal() { document.getElementById('deliveryModal').style.display = 'flex'; }
function closeDeliveryModal() { document.getElementById('deliveryModal').style.display = 'none'; }
function openConfirmModal() { document.getElementById('confirmModal').style.display = 'flex'; }
function closeConfirmModal() { document.getElementById('confirmModal').style.display = 'none'; }
function openDisputeModal() { document.getElementById('disputeModal').style.display = 'flex'; }
function closeDisputeModal() { document.getElementById('disputeModal').style.display = 'none'; }

function initiateRemainingPayment() {
    window.location.href = 'pay_rent.php?transaction_id=<?php echo $transaction_id; ?>&pay=remaining';
}

window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
}
</script>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>