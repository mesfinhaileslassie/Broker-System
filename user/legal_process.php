<?php
// user/legal_process.php - Modern Redesigned Legal Process Page

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    header('Location: /broker_system/auth/login.php');
    exit;
}

$page_title = 'Legal Process';
ob_start();

require_once '../config/database.php';
require_once '../includes/functions.php';

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];

// Get transactions pending legal confirmation
$pending_legal = $conn->query("
    SELECT t.id, l.title, t.total_amount,
           CASE WHEN t.buyer_id = $user_id THEN 'buyer' ELSE 'seller' END as my_role,
           t.buyer_legal_confirmed, t.seller_legal_confirmed,
           t.status, t.created_at
    FROM transactions t
    JOIN listings l ON t.listing_id = l.id
    WHERE (t.buyer_id = $user_id OR t.seller_id = $user_id)
    AND t.status = 'deposits_complete'
    ORDER BY t.created_at DESC
");

// Get statistics
$total_pending = $pending_legal ? $pending_legal->num_rows : 0;
$my_pending = 0;
$other_pending = 0;

if ($pending_legal) {
    $pending_legal_data = [];
    while ($row = $pending_legal->fetch_assoc()) {
        $pending_legal_data[] = $row;
        $my_confirmed = ($row['my_role'] == 'buyer') ? $row['buyer_legal_confirmed'] : $row['seller_legal_confirmed'];
        $other_confirmed = ($row['my_role'] == 'buyer') ? $row['seller_legal_confirmed'] : $row['buyer_legal_confirmed'];
        
        if (!$my_confirmed) $my_pending++;
        if (!$other_confirmed) $other_pending++;
    }
    // Reset pointer
    $pending_legal = $pending_legal_data;
}

$conn->close();
?>

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
    
    .legal-container {
        max-width: 1000px;
        margin: 0 auto;
    }
    
    /* Header Section */
    .legal-header {
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        border-radius: 28px;
        padding: 40px;
        margin-bottom: 32px;
        color: white;
        position: relative;
        overflow: hidden;
    }
    
    .legal-header::before {
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
    
    .legal-header-content {
        position: relative;
        z-index: 1;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 20px;
    }
    
    .legal-header h1 {
        font-size: 28px;
        font-weight: 700;
        margin-bottom: 8px;
    }
    
    .legal-header p {
        font-size: 14px;
        opacity: 0.9;
    }
    
    .dashboard-link {
        background: rgba(255,255,255,0.2);
        color: white;
        padding: 10px 20px;
        border-radius: 40px;
        text-decoration: none;
        font-weight: 500;
        transition: all 0.3s;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }
    
    .dashboard-link:hover {
        background: rgba(255,255,255,0.3);
        transform: translateY(-2px);
    }
    
    /* Stats Cards */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 20px;
        margin-bottom: 32px;
    }
    
    .stat-card {
        background: white;
        border-radius: 20px;
        padding: 24px;
        text-align: center;
        transition: all 0.3s;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        border: 1px solid var(--border);
    }
    
    .stat-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 24px -8px rgba(0,0,0,0.15);
    }
    
    .stat-icon {
        width: 50px;
        height: 50px;
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        border-radius: 15px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 12px;
        font-size: 24px;
        color: white;
    }
    
    .stat-value {
        font-size: 28px;
        font-weight: 700;
        color: var(--dark);
    }
    
    .stat-label {
        font-size: 12px;
        color: var(--gray);
        margin-top: 4px;
    }
    
    /* Legal Cards */
    .legal-card {
        background: white;
        border-radius: 24px;
        margin-bottom: 24px;
        overflow: hidden;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        transition: all 0.3s;
        border: 1px solid var(--border);
    }
    
    .legal-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 24px -8px rgba(0,0,0,0.15);
    }
    
    .card-status-banner {
        padding: 12px 24px;
        background: linear-gradient(135deg, var(--warning), #ea580c);
        color: white;
        font-size: 12px;
        font-weight: 600;
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 10px;
    }
    
    .card-status-banner.completed {
        background: linear-gradient(135deg, var(--success), #059669);
    }
    
    .card-status-banner i {
        margin-right: 6px;
    }
    
    .card-body {
        padding: 24px;
    }
    
    .transaction-title {
        font-size: 18px;
        font-weight: 700;
        color: var(--dark);
        margin-bottom: 16px;
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }
    
    .transaction-id {
        background: var(--light);
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 11px;
        color: var(--gray);
        font-weight: normal;
    }
    
    .transaction-amount {
        font-size: 24px;
        font-weight: 700;
        color: var(--primary);
        margin-bottom: 20px;
    }
    
    /* Progress Steps */
    .progress-steps {
        margin: 24px 0;
    }
    
    .step {
        display: flex;
        align-items: center;
        margin-bottom: 16px;
        position: relative;
    }
    
    .step-marker {
        width: 40px;
        height: 40px;
        background: var(--light);
        border: 2px solid var(--border);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
        color: var(--gray);
        margin-right: 16px;
        z-index: 1;
        background: white;
    }
    
    .step.completed .step-marker {
        background: var(--success);
        border-color: var(--success);
        color: white;
    }
    
    .step.active .step-marker {
        border-color: var(--primary);
        color: var(--primary);
    }
    
    .step-content {
        flex: 1;
    }
    
    .step-title {
        font-weight: 600;
        color: var(--dark);
        margin-bottom: 4px;
    }
    
    .step-desc {
        font-size: 11px;
        color: var(--gray);
    }
    
    .step-status {
        font-size: 12px;
        font-weight: 500;
    }
    
    .step-status.completed {
        color: var(--success);
    }
    
    .step-status.pending {
        color: var(--warning);
    }
    
    /* Confirmation Cards */
    .confirmation-card {
        background: var(--light);
        border-radius: 16px;
        padding: 16px;
        margin: 16px 0;
    }
    
    .confirmation-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 10px 0;
        border-bottom: 1px solid var(--border);
    }
    
    .confirmation-row:last-child {
        border-bottom: none;
    }
    
    .confirmation-label {
        font-size: 13px;
        color: var(--gray);
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .badge {
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    
    .badge-success {
        background: #d1fae5;
        color: #059669;
    }
    
    .badge-warning {
        background: #fed7aa;
        color: #ea580c;
    }
    
    .badge-info {
        background: #dbeafe;
        color: #1e40af;
    }
    
    /* Buttons */
    .btn {
        padding: 12px 28px;
        border-radius: 40px;
        font-weight: 600;
        font-size: 14px;
        cursor: pointer;
        transition: all 0.3s;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        text-decoration: none;
        border: none;
    }
    
    .btn-primary {
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        color: white;
    }
    
    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(102,126,234,0.4);
    }
    
    .btn-success {
        background: var(--success);
        color: white;
    }
    
    .btn-success:hover {
        background: #059669;
        transform: translateY(-2px);
    }
    
    .btn-outline {
        background: transparent;
        border: 1px solid var(--border);
        color: var(--gray);
    }
    
    .btn-outline:hover {
        border-color: var(--primary);
        color: var(--primary);
    }
    
    /* Empty State */
    .empty-state {
        background: white;
        border-radius: 28px;
        padding: 60px;
        text-align: center;
        border: 1px solid var(--border);
    }
    
    .empty-state-icon {
        width: 80px;
        height: 80px;
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 20px;
        font-size: 36px;
        color: white;
    }
    
    .empty-state h3 {
        font-size: 20px;
        color: var(--dark);
        margin-bottom: 8px;
    }
    
    .empty-state p {
        color: var(--gray);
        margin-bottom: 20px;
    }
    
    /* Responsive */
    @media (max-width: 768px) {
        .legal-header {
            padding: 24px;
        }
        .legal-header-content {
            flex-direction: column;
            text-align: center;
        }
        .stats-grid {
            grid-template-columns: 1fr;
        }
        .step {
            flex-direction: column;
            text-align: center;
        }
        .step-marker {
            margin-right: 0;
            margin-bottom: 8px;
        }
        .confirmation-row {
            flex-direction: column;
            gap: 8px;
            align-items: flex-start;
        }
        .btn {
            width: 100%;
            justify-content: center;
        }
    }
</style>

<div class="legal-container">
    <!-- Header -->
    <div class="legal-header">
        <div class="legal-header-content">
            <div>
                <h1><i class="fas fa-gavel"></i> Legal Process</h1>
                <p>Complete legal documentation and confirmations for your transactions</p>
            </div>
            <a href="dashboard.php" class="dashboard-link">
                <i class="fas fa-home"></i> Dashboard
            </a>
        </div>
    </div>
    
    <!-- Stats Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-clock"></i></div>
            <div class="stat-value"><?php echo $total_pending; ?></div>
            <div class="stat-label">Pending Transactions</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-user-check"></i></div>
            <div class="stat-value"><?php echo $my_pending; ?></div>
            <div class="stat-label">Waiting for You</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-users"></i></div>
            <div class="stat-value"><?php echo $other_pending; ?></div>
            <div class="stat-label">Waiting for Other Party</div>
        </div>
    </div>
    
    <!-- Legal Process Cards -->
    <?php if (!empty($pending_legal)): ?>
        <?php foreach($pending_legal as $legal): 
            $my_role = $legal['my_role'];
            $my_confirmed = ($my_role == 'buyer') ? $legal['buyer_legal_confirmed'] : $legal['seller_legal_confirmed'];
            $other_confirmed = ($my_role == 'buyer') ? $legal['seller_legal_confirmed'] : $legal['buyer_legal_confirmed'];
            $both_confirmed = ($my_confirmed && $other_confirmed);
        ?>
            <div class="legal-card">
                <!-- Status Banner -->
                <div class="card-status-banner <?php echo $both_confirmed ? 'completed' : ''; ?>">
                    <span>
                        <i class="fas <?php echo $both_confirmed ? 'fa-check-circle' : 'fa-hourglass-half'; ?>"></i>
                        <?php echo $both_confirmed ? 'Legal Process Complete!' : 'Legal Process in Progress'; ?>
                    </span>
                    <span>
                        <i class="fas fa-calendar"></i> 
                        <?php echo date('M d, Y', strtotime($legal['created_at'])); ?>
                    </span>
                </div>
                
                <div class="card-body">
                    <!-- Transaction Info -->
                    <div class="transaction-title">
                        <?php echo htmlspecialchars($legal['title']); ?>
                        <span class="transaction-id">#<?php echo $legal['id']; ?></span>
                    </div>
                    <div class="transaction-amount">
                        <?php echo formatMoney($legal['total_amount']); ?>
                    </div>
                    
                    <!-- Progress Steps -->
                    <div class="progress-steps">
                        <div class="step <?php echo $my_confirmed ? 'completed' : 'active'; ?>">
                            <div class="step-marker">
                                <?php if ($my_confirmed): ?>
                                    <i class="fas fa-check"></i>
                                <?php else: ?>
                                    1
                                <?php endif; ?>
                            </div>
                            <div class="step-content">
                                <div class="step-title">Your Confirmation</div>
                                <div class="step-desc">You need to confirm the legal process</div>
                            </div>
                            <div class="step-status <?php echo $my_confirmed ? 'completed' : 'pending'; ?>">
                                <?php if ($my_confirmed): ?>
                                    <i class="fas fa-check-circle"></i> Completed
                                <?php else: ?>
                                    <i class="fas fa-clock"></i> Pending
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="step <?php echo $other_confirmed ? 'completed' : ($my_confirmed ? 'active' : ''); ?>">
                            <div class="step-marker">
                                <?php if ($other_confirmed): ?>
                                    <i class="fas fa-check"></i>
                                <?php else: ?>
                                    2
                                <?php endif; ?>
                            </div>
                            <div class="step-content">
                                <div class="step-title">Other Party Confirmation</div>
                                <div class="step-desc">Waiting for <?php echo ($my_role == 'buyer') ? 'seller' : 'buyer'; ?> to confirm</div>
                            </div>
                            <div class="step-status <?php echo $other_confirmed ? 'completed' : 'pending'; ?>">
                                <?php if ($other_confirmed): ?>
                                    <i class="fas fa-check-circle"></i> Completed
                                <?php else: ?>
                                    <i class="fas fa-clock"></i> Pending
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Confirmation Details -->
                    <div class="confirmation-card">
                        <div class="confirmation-row">
                            <span class="confirmation-label">
                                <i class="fas fa-user"></i> Your Role
                            </span>
                            <span class="badge badge-info">
                                <i class="fas <?php echo $my_role == 'buyer' ? 'fa-shopping-cart' : 'fa-store'; ?>"></i>
                                <?php echo ucfirst($my_role); ?>
                            </span>
                        </div>
                        <div class="confirmation-row">
                            <span class="confirmation-label">
                                <i class="fas fa-gavel"></i> Your Legal Status
                            </span>
                            <?php if ($my_confirmed): ?>
                                <span class="badge badge-success">
                                    <i class="fas fa-check-circle"></i> Confirmed
                                </span>
                            <?php else: ?>
                                <span class="badge badge-warning">
                                    <i class="fas fa-clock"></i> Pending
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="confirmation-row">
                            <span class="confirmation-label">
                                <i class="fas fa-store"></i> Other Party Status
                            </span>
                            <?php if ($other_confirmed): ?>
                                <span class="badge badge-success">
                                    <i class="fas fa-check-circle"></i> Confirmed
                                </span>
                            <?php else: ?>
                                <span class="badge badge-warning">
                                    <i class="fas fa-clock"></i> Pending
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Action Buttons -->
                    <?php if (!$my_confirmed): ?>
                        <a href="transaction.php?id=<?php echo $legal['id']; ?>" class="btn btn-primary" style="width: 100%;">
                            <i class="fas fa-file-signature"></i> Complete Legal Process
                        </a>
                    <?php elseif ($my_confirmed && !$other_confirmed): ?>
                        <div style="text-align: center;">
                            <span class="badge badge-warning" style="background: #fed7aa; color: #ea580c; padding: 10px 20px;">
                                <i class="fas fa-clock"></i> Waiting for <?php echo ($my_role == 'buyer') ? 'Seller' : 'Buyer'; ?> to Confirm
                            </span>
                        </div>
                    <?php else: ?>
                        <a href="transaction.php?id=<?php echo $legal['id']; ?>" class="btn btn-success" style="width: 100%;">
                            <i class="fas fa-truck"></i> Proceed to Delivery Confirmation
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <!-- Empty State -->
        <div class="empty-state">
            <div class="empty-state-icon">
                <i class="fas fa-check-circle"></i>
            </div>
            <h3>No Pending Legal Processes</h3>
            <p>All your transactions have completed legal confirmation or are awaiting deposits.</p>
            <div style="display: flex; gap: 12px; justify-content: center; flex-wrap: wrap;">
                <a href="dashboard.php" class="btn btn-primary">
                    <i class="fas fa-home"></i> Go to Dashboard
                </a>
                <a href="browse.php" class="btn btn-outline">
                    <i class="fas fa-search"></i> Browse Listings
                </a>
            </div>
        </div>
    <?php endif; ?>
    
    <!-- Information Note -->
    <div style="background: linear-gradient(135deg, #dbeafe, #e0e7ff); border-radius: 20px; padding: 20px; margin-top: 24px;">
        <div style="display: flex; align-items: center; gap: 16px; flex-wrap: wrap;">
            <i class="fas fa-info-circle" style="font-size: 32px; color: var(--primary);"></i>
            <div style="flex: 1;">
                <strong style="color: var(--dark);">What is the Legal Process?</strong>
                <p style="font-size: 13px; color: var(--gray); margin-top: 4px;">
                    Both buyer and seller must confirm that all legal documentation, contracts, and requirements 
                    for this transaction are completed. This protects both parties and ensures a smooth transfer 
                    of ownership or service delivery.
                </p>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>