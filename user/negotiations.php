<?php
// ============================================
// FILE: broker_system/user/negotiations.php
// ============================================
// User Negotiations Dashboard with Buttons - FIXED

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

// Check login FIRST
requireLogin();

$page_title = 'My Negotiations';
ob_start();

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];

// Get all negotiations for this user
$negotiations = $conn->query("
    SELECT ln.*, l.title, l.type, l.price, l.cover_image,
           l.approval_status, l.status as listing_status
    FROM listing_negotiations ln
    JOIN listings l ON ln.listing_id = l.id
    WHERE ln.seller_id = $user_id
    ORDER BY ln.created_at DESC
");

// Calculate stats
$total = 0;
$pending = 0;
$agreed = 0;
$published = 0;
$negotiations_array = array();

if ($negotiations) {
    while($row = $negotiations->fetch_assoc()) {
        $negotiations_array[] = $row;
        $total++;
        if ($row['status'] == 'under_review' || $row['status'] == 'commission_proposed' || $row['status'] == 'counter_offer_sent') {
            $pending++;
        } elseif ($row['status'] == 'agreement_accepted') {
            $agreed++;
        } elseif ($row['status'] == 'published') {
            $published++;
        }
    }
}

$conn->close();
?>

<style>
    .negotiations-container {
        max-width: 1200px;
        margin: 0 auto;
    }
    
    .page-header {
        margin-bottom: 28px;
    }
    
    .page-header h1 {
        font-size: 28px;
        font-weight: 700;
        color: #0f172a;
        margin-bottom: 8px;
    }
    
    .page-header p {
        color: #64748b;
    }
    
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 20px;
        margin-bottom: 28px;
    }
    
    .stat-card {
        background: white;
        border-radius: 20px;
        padding: 20px;
        text-align: center;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    
    .stat-value {
        font-size: 28px;
        font-weight: 700;
        color: #0f172a;
    }
    
    .stat-label {
        font-size: 12px;
        color: #64748b;
        margin-top: 4px;
    }
    
    .negotiation-card {
        background: white;
        border-radius: 20px;
        margin-bottom: 20px;
        overflow: hidden;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        transition: all 0.3s;
    }
    
    .negotiation-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(0,0,0,0.1);
    }
    
    .card-header {
        padding: 20px;
        background: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 12px;
    }
    
    .listing-title {
        font-size: 18px;
        font-weight: 700;
        color: #0f172a;
    }
    
    .listing-price {
        font-size: 20px;
        font-weight: 700;
        color: #667eea;
    }
    
    .status-badge {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
    }
    
    .status-under_review { background: #fef3c7; color: #92400e; }
    .status-commission_proposed { background: #dbeafe; color: #1e40af; }
    .status-counter_offer_sent { background: #ede9fe; color: #6b21a5; }
    .status-agreement_accepted { background: #d1fae5; color: #065f46; }
    .status-published { background: #10b98120; color: #059669; }
    .status-rejected { background: #fee2e2; color: #dc2626; }
    
    .card-body {
        padding: 20px;
    }
    
    .offer-details {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 16px;
        margin-bottom: 20px;
        padding: 16px;
        background: #f8fafc;
        border-radius: 12px;
    }
    
    .offer-item {
        text-align: center;
    }
    
    .offer-label {
        font-size: 11px;
        color: #64748b;
        margin-bottom: 4px;
    }
    
    .offer-value {
        font-size: 18px;
        font-weight: 700;
    }
    
    .offer-value.proposed {
        color: #667eea;
    }
    
    .offer-value.counter {
        color: #f59e0b;
    }
    
    .action-buttons {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
        margin-top: 16px;
        padding-top: 16px;
        border-top: 1px solid #e2e8f0;
    }
    
    .btn {
        padding: 10px 20px;
        border-radius: 40px;
        font-weight: 600;
        font-size: 13px;
        cursor: pointer;
        transition: all 0.3s;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        border: none;
    }
    
    .btn-primary {
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: white;
    }
    
    .btn-success {
        background: #10b981;
        color: white;
    }
    
    .btn-warning {
        background: #f59e0b;
        color: white;
    }
    
    .btn-danger {
        background: #ef4444;
        color: white;
    }
    
    .btn-outline {
        background: transparent;
        border: 1px solid #e2e8f0;
        color: #64748b;
    }
    
    .btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    }
    
    .empty-state {
        text-align: center;
        padding: 60px;
        background: white;
        border-radius: 20px;
    }
    
    .empty-state i {
        font-size: 64px;
        color: #cbd5e1;
        margin-bottom: 16px;
    }
    
    @media (max-width: 768px) {
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }
        .offer-details {
            grid-template-columns: 1fr;
        }
        .action-buttons {
            flex-direction: column;
        }
        .btn {
            justify-content: center;
        }
    }
</style>

<div class="negotiations-container">
    <div class="page-header">
        <h1><i class="fas fa-handshake"></i> My Negotiations</h1>
        <p>Track and manage your listing negotiations</p>
    </div>
    
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?php echo $total; ?></div>
            <div class="stat-label">Total Negotiations</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $pending; ?></div>
            <div class="stat-label">Active</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $agreed; ?></div>
            <div class="stat-label">Awaiting Payment</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $published; ?></div>
            <div class="stat-label">Published</div>
        </div>
    </div>
    
    <?php if (!empty($negotiations_array)): ?>
        <?php foreach($negotiations_array as $neg): 
            $status_class = 'status-' . str_replace('_', '-', $neg['status']);
        ?>
            <div class="negotiation-card">
                <div class="card-header">
                    <div>
                        <div class="listing-title"><?php echo htmlspecialchars($neg['title']); ?></div>
                        <div style="font-size: 12px; color: #64748b; margin-top: 4px;">
                            <?php 
                            if ($neg['type'] == 'rental') echo '🏠 Property Rental';
                            elseif ($neg['type'] == 'product') echo '🚗 Car/Product';
                            else echo '💼 Job Listing';
                            ?>
                        </div>
                    </div>
                    <div>
                        <div class="listing-price"><?php echo formatMoney($neg['price']); ?></div>
                        <div class="status-badge <?php echo $status_class; ?>" style="margin-top: 8px;">
                            <?php 
                            $status_text = str_replace('_', ' ', $neg['status']);
                            echo ucfirst($status_text);
                            ?>
                        </div>
                    </div>
                </div>
                
                <div class="card-body">
                    <!-- Offer Details -->
                    <div class="offer-details">
                        <div class="offer-item">
                            <div class="offer-label">Proposed Commission</div>
                            <div class="offer-value proposed">
                                <?php echo $neg['proposed_commission'] ? $neg['proposed_commission'] . '%' : '—'; ?>
                            </div>
                        </div>
                        <div class="offer-item">
                            <div class="offer-label">Proposed Deposit</div>
                            <div class="offer-value proposed">
                                <?php echo $neg['proposed_deposit'] ? formatMoney($neg['proposed_deposit']) : '—'; ?>
                            </div>
                        </div>
                        <?php if ($neg['counter_commission']): ?>
                        <div class="offer-item">
                            <div class="offer-label">Your Counter Offer</div>
                            <div class="offer-value counter">
                                <?php echo $neg['counter_commission'] . '% / ' . formatMoney($neg['counter_deposit']); ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- ACTION BUTTONS -->
                    <div class="action-buttons">
                        <!-- View Details Button -->
                        <a href="negotiate.php?id=<?php echo $neg['id']; ?>" class="btn btn-primary">
                            <i class="fas fa-comments"></i> View & Negotiate
                        </a>
                        
                        <!-- Accept Terms Button -->
                        <?php if ($neg['status'] == 'commission_proposed' || $neg['status'] == 'counter_offer_sent'): ?>
                            <a href="negotiate.php?id=<?php echo $neg['id']; ?>&action=accept" class="btn btn-success">
                                <i class="fas fa-check-circle"></i> Accept Terms
                            </a>
                        <?php endif; ?>
                        
                        <!-- Counter Offer Button -->
                        <?php if ($neg['status'] == 'commission_proposed'): ?>
                            <a href="negotiate.php?id=<?php echo $neg['id']; ?>&action=counter" class="btn btn-warning">
                                <i class="fas fa-exchange-alt"></i> Send Counter Offer
                            </a>
                        <?php endif; ?>
                        
                        <!-- Pay Deposit Button -->
                        <?php if ($neg['status'] == 'agreement_accepted'): ?>
                            <a href="pay_deposit.php?negotiation_id=<?php echo $neg['id']; ?>" class="btn btn-success">
                                <i class="fas fa-credit-card"></i> Pay Deposit to Publish
                            </a>
                        <?php endif; ?>
                        
                        <!-- View Listing Button -->
                        <?php if ($neg['status'] == 'published'): ?>
                            <a href="product.php?id=<?php echo $neg['listing_id']; ?>" class="btn btn-outline">
                                <i class="fas fa-eye"></i> View Published Listing
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-handshake"></i>
            <h3>No Negotiations Yet</h3>
            <p>When you submit a listing, our team will review it and start negotiations here.</p>
            <a href="post_listing.php" class="btn btn-primary" style="margin-top: 16px; display: inline-block;">
                <i class="fas fa-plus-circle"></i> Submit a Listing
            </a>
        </div>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>