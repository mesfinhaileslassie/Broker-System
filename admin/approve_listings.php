<?php
// admin/approve_listings.php - Updated to show all pending listings

$page_title = 'Approve Listings';
ob_start();

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

if (!isLoggedIn() || $_SESSION['user_role'] != 'admin') {
    header('Location: /broker_system/auth/login.php');
    exit;
}

$conn = getDbConnection();
$message = '';
$error = '';

// Handle negotiation actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['propose_terms'])) {
        $listing_id = intval($_POST['listing_id']);
        $commission = floatval($_POST['commission_percent']);
        $deposit = floatval($_POST['deposit_amount']);
        $notes = $conn->real_escape_string($_POST['admin_notes'] ?? '');
        
        // Check if negotiation exists
        $neg_check = $conn->query("SELECT id FROM listing_negotiations WHERE listing_id = $listing_id");
        if ($neg_check->num_rows > 0) {
            $negotiation_id = $neg_check->fetch_assoc()['id'];
            $update = $conn->prepare("
                UPDATE listing_negotiations 
                SET proposed_commission = ?, proposed_deposit = ?, admin_notes = ?, 
                    status = 'commission_proposed', updated_at = NOW()
                WHERE id = ?
            ");
            $update->bind_param("ddsi", $commission, $deposit, $notes, $negotiation_id);
            $update->execute();
        } else {
            $listing = $conn->query("SELECT seller_id FROM listings WHERE id = $listing_id")->fetch_assoc();
            $insert = $conn->prepare("
                INSERT INTO listing_negotiations (listing_id, seller_id, proposed_commission, proposed_deposit, admin_notes, status, created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, 'commission_proposed', NOW(), NOW())
            ");
            $insert->bind_param("iidds", $listing_id, $listing['seller_id'], $commission, $deposit, $notes);
            $insert->execute();
            $negotiation_id = $conn->insert_id;
        }
        
        // Update listing approval status
        $conn->query("UPDATE listings SET approval_status = 'approved' WHERE id = $listing_id");
        
        $listing = $conn->query("SELECT title, seller_id FROM listings WHERE id = $listing_id")->fetch_assoc();
        $notif_stmt = $conn->prepare("
            INSERT INTO notifications (user_id, title, message, created_at) 
            VALUES (?, 'Commission Proposal', 'Admin has proposed {$commission}% commission and " . formatMoney($deposit) . " deposit for your listing \"{$listing['title']}\". Please accept to publish.', NOW())
        ");
        $notif_stmt->bind_param("i", $listing['seller_id']);
        $notif_stmt->execute();
        
        $message = "Terms proposed to seller! They will review and respond.";
    }
    
    if (isset($_POST['accept_counter'])) {
        $negotiation_id = intval($_POST['negotiation_id']);
        $conn->query("
            UPDATE listing_negotiations 
            SET proposed_commission = counter_commission,
                proposed_deposit = counter_deposit,
                counter_commission = NULL,
                counter_deposit = NULL,
                status = 'agreement_accepted',
                accepted_at = NOW()
            WHERE id = $negotiation_id
        ");
        
        $neg = $conn->query("SELECT seller_id, listing_id FROM listing_negotiations WHERE id = $negotiation_id")->fetch_assoc();
        $listing = $conn->query("SELECT title FROM listings WHERE id = {$neg['listing_id']}")->fetch_assoc();
        
        // Update listing to approved
        $conn->query("UPDATE listings SET approval_status = 'approved' WHERE id = {$neg['listing_id']}");
        
        $notif_stmt = $conn->prepare("
            INSERT INTO notifications (user_id, title, message, created_at) 
            VALUES (?, 'Counter Offer Accepted', 'Your counter offer for \"{$listing['title']}\" has been accepted! Please pay the deposit to publish your listing.', NOW())
        ");
        $notif_stmt->bind_param("i", $neg['seller_id']);
        $notif_stmt->execute();
        
        $message = "Counter offer accepted! Waiting for seller payment.";
    }
    
    if (isset($_POST['reject_counter'])) {
        $negotiation_id = intval($_POST['negotiation_id']);
        $conn->query("
            UPDATE listing_negotiations 
            SET counter_commission = NULL,
                counter_deposit = NULL,
                status = 'commission_proposed'
            WHERE id = $negotiation_id
        ");
        $message = "Counter offer rejected. Original proposal remains active.";
    }
}

// Get ALL pending listings (approval_status = 'pending')
$pendingListings = $conn->query("
    SELECT l.*, u.full_name as seller_name, u.email as seller_email,
           ln.id as negotiation_id, ln.status as negotiation_status,
           ln.proposed_commission, ln.proposed_deposit,
           ln.counter_commission, ln.counter_deposit, ln.counter_message
    FROM listings l
    JOIN users u ON l.seller_id = u.id
    LEFT JOIN listing_negotiations ln ON l.id = ln.listing_id
    WHERE l.approval_status = 'pending'
    ORDER BY l.created_at DESC
");

// Also check for listings that might need payment
$paymentNeeded = $conn->query("
    SELECT l.*, u.full_name as seller_name, u.email as seller_email,
           ln.id as negotiation_id, ln.status as negotiation_status,
           ln.proposed_commission, ln.proposed_deposit,
           ln.counter_commission, ln.counter_deposit
    FROM listings l
    JOIN users u ON l.seller_id = u.id
    LEFT JOIN listing_negotiations ln ON l.id = ln.listing_id
    WHERE l.approval_status = 'approved' AND l.status = 'pending'
    ORDER BY l.created_at DESC
");

// Get statistics
$stats = [
    'pending' => $conn->query("SELECT COUNT(*) as count FROM listings WHERE approval_status = 'pending'")->fetch_assoc()['count'],
    'negotiating' => $conn->query("SELECT COUNT(*) as count FROM listing_negotiations WHERE status IN ('commission_proposed', 'counter_offer_sent')")->fetch_assoc()['count'],
    'pending_payment' => $conn->query("SELECT COUNT(*) as count FROM listings WHERE approval_status = 'approved' AND status = 'pending'")->fetch_assoc()['count'],
];

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approve Listings - Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* Your existing styles */
        :root {
            --primary: #4f46e5;
            --primary-dark: #4338ca;
            --primary-light: #eef2ff;
            --secondary: #7c3aed;
            --success: #10b981;
            --success-light: #d1fae5;
            --warning: #f59e0b;
            --warning-light: #fef3c7;
            --danger: #ef4444;
            --danger-light: #fee2e2;
            --info: #3b82f6;
            --info-light: #dbeafe;
            --dark: #1e293b;
            --gray: #64748b;
            --gray-light: #f8fafc;
            --border: #e2e8f0;
            --card-shadow: 0 1px 3px rgba(0,0,0,0.05);
            --card-shadow-hover: 0 10px 25px -5px rgba(0,0,0,0.08);
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            border-radius: 1rem;
            padding: 1.5rem;
            transition: all 0.3s ease;
            border: 1px solid var(--border);
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--card-shadow-hover);
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 800;
            color: var(--dark);
        }
        
        .stat-label {
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--gray);
            margin-top: 0.5rem;
        }
        
        .listing-card {
            background: white;
            border-radius: 1rem;
            margin-bottom: 1.5rem;
            overflow: hidden;
            border: 1px solid var(--border);
        }
        
        .listing-card:hover {
            box-shadow: var(--card-shadow-hover);
        }
        
        .card-header {
            padding: 1.25rem 1.5rem;
            background: var(--gray-light);
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .listing-info h3 {
            font-size: 1rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }
        
        .listing-meta {
            display: flex;
            gap: 1rem;
            font-size: 0.7rem;
            color: var(--gray);
            flex-wrap: wrap;
        }
        
        .listing-price {
            font-size: 1.25rem;
            font-weight: 800;
            color: var(--primary);
        }
        
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.75rem;
            border-radius: 2rem;
            font-size: 0.7rem;
            font-weight: 600;
        }
        
        .badge-pending { background: var(--warning-light); color: #92400e; }
        .badge-negotiating { background: var(--info-light); color: #1e40af; }
        .badge-payment { background: var(--success-light); color: #065f46; }
        
        .negotiation-box {
            padding: 1.25rem 1.5rem;
            background: #fafcff;
            border-bottom: 1px solid var(--border);
        }
        
        .offer-grid {
            display: flex;
            gap: 2rem;
            flex-wrap: wrap;
            margin-bottom: 1rem;
        }
        
        .offer-item {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }
        
        .offer-label {
            font-size: 0.7rem;
            font-weight: 500;
            color: var(--gray);
            text-transform: uppercase;
        }
        
        .offer-value {
            font-size: 1.125rem;
            font-weight: 700;
        }
        
        .offer-value.proposed { color: var(--primary); }
        .offer-value.counter { color: var(--warning); }
        
        .counter-message {
            background: var(--warning-light);
            border-radius: 0.75rem;
            padding: 0.75rem 1rem;
            font-size: 0.8rem;
            margin-top: 1rem;
            border-left: 3px solid var(--warning);
        }
        
        .btn-group {
            padding: 1rem 1.5rem;
            background: white;
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            border-top: 1px solid var(--border);
        }
        
        .btn {
            padding: 0.5rem 1.25rem;
            border-radius: 2rem;
            font-weight: 600;
            font-size: 0.8rem;
            cursor: pointer;
            transition: all 0.2s ease;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(79,70,229,0.3);
        }
        
        .btn-success {
            background: var(--success);
            color: white;
        }
        
        .btn-success:hover {
            background: #059669;
        }
        
        .btn-danger {
            background: var(--danger);
            color: white;
        }
        
        .btn-outline {
            background: transparent;
            border: 1px solid var(--border);
            color: var(--gray);
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            backdrop-filter: blur(4px);
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }
        
        .modal-content {
            background: white;
            border-radius: 1.5rem;
            padding: 1.75rem;
            width: 520px;
            max-width: 90%;
            animation: modalIn 0.3s ease;
        }
        
        @keyframes modalIn {
            from { opacity: 0; transform: scale(0.95); }
            to { opacity: 1; transform: scale(1); }
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.25rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--border);
        }
        
        .close-modal {
            cursor: pointer;
            font-size: 1.5rem;
            color: var(--gray);
        }
        
        .close-modal:hover {
            color: var(--danger);
        }
        
        .form-group {
            margin-bottom: 1rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            font-size: 0.8rem;
        }
        
        .form-group input, .form-group textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--border);
            border-radius: 0.75rem;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        
        .alert {
            padding: 0.75rem 1rem;
            border-radius: 0.75rem;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            animation: slideIn 0.3s ease;
        }
        
        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .alert-success {
            background: var(--success-light);
            color: #065f46;
            border-left: 4px solid var(--success);
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
            background: white;
            border-radius: 1rem;
            border: 1px solid var(--border);
        }
        
        .empty-state-icon {
            font-size: 4rem;
            color: #cbd5e1;
            margin-bottom: 1rem;
        }
        
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            .card-header {
                flex-direction: column;
                align-items: flex-start;
            }
            .offer-grid {
                flex-direction: column;
                gap: 0.75rem;
            }
            .btn-group {
                flex-direction: column;
            }
            .btn {
                justify-content: center;
            }
        }
    </style>
</head>

<div>
    <!-- Stats Grid -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?php echo $stats['pending']; ?></div>
            <div class="stat-label">Pending Approval</div>
            <div class="stat-icon"><i class="fas fa-clock"></i></div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $stats['negotiating']; ?></div>
            <div class="stat-label">Under Negotiation</div>
            <div class="stat-icon"><i class="fas fa-handshake"></i></div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $stats['pending_payment']; ?></div>
            <div class="stat-label">Awaiting Payment</div>
            <div class="stat-icon"><i class="fas fa-credit-card"></i></div>
        </div>
    </div>
    
    <?php if ($message): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> <?php echo $message; ?>
        </div>
    <?php endif; ?>
    
    <!-- Pending Approval Listings -->
    <h2 style="margin-bottom: 1rem; font-size: 1.25rem;">Listings Awaiting Approval</h2>
    
    <?php if ($pendingListings && $pendingListings->num_rows > 0): ?>
        <?php while($listing = $pendingListings->fetch_assoc()): 
            $has_negotiation = $listing['negotiation_id'];
            $is_proposed = ($listing['negotiation_status'] == 'commission_proposed');
            $has_counter = ($listing['negotiation_status'] == 'counter_offer_sent');
        ?>
            <div class="listing-card">
                <div class="card-header">
                    <div class="listing-info">
                        <h3><?php echo htmlspecialchars($listing['title']); ?></h3>
                        <div class="listing-meta">
                            <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($listing['seller_name']); ?></span>
                            <span><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($listing['seller_email']); ?></span>
                            <span class="badge badge-pending"><i class="fas fa-hourglass-half"></i> Pending Review</span>
                        </div>
                    </div>
                    <div class="listing-price"><?php echo formatMoney($listing['price']); ?></div>
                </div>
                
                <?php if ($listing['proposed_commission']): ?>
                <div class="negotiation-box">
                    <div class="offer-grid">
                        <div class="offer-item">
                            <span class="offer-label">Proposed Commission</span>
                            <span class="offer-value proposed"><?php echo $listing['proposed_commission']; ?>%</span>
                        </div>
                        <div class="offer-item">
                            <span class="offer-label">Proposed Deposit</span>
                            <span class="offer-value proposed"><?php echo formatMoney($listing['proposed_deposit']); ?></span>
                        </div>
                        <?php if ($listing['counter_commission']): ?>
                        <div class="offer-item">
                            <span class="offer-label">Seller Counter Offer</span>
                            <span class="offer-value counter"><?php echo $listing['counter_commission']; ?>% / <?php echo formatMoney($listing['counter_deposit']); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($listing['counter_message']): ?>
                    <div class="counter-message">
                        <i class="fas fa-comment-dots"></i> <strong>Seller's Note:</strong> <?php echo htmlspecialchars($listing['counter_message']); ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
                <div class="btn-group">
                    <?php if (!$has_negotiation): ?>
                        <button onclick="openProposeModal(<?php echo $listing['id']; ?>, <?php echo $listing['price']; ?>)" class="btn btn-primary">
                            <i class="fas fa-percent"></i> Propose Terms
                        </button>
                        
                    <?php elseif ($has_counter): ?>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="negotiation_id" value="<?php echo $listing['negotiation_id']; ?>">
                            <input type="hidden" name="action" value="accept_counter">
                            <button type="submit" name="accept_counter" class="btn btn-success" onclick="return confirm('Accept this counter offer?')">
                                <i class="fas fa-check"></i> Accept Counter Offer
                            </button>
                        </form>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="negotiation_id" value="<?php echo $listing['negotiation_id']; ?>">
                            <input type="hidden" name="action" value="reject_counter">
                            <button type="submit" name="reject_counter" class="btn btn-danger" onclick="return confirm('Reject this counter offer?')">
                                <i class="fas fa-times"></i> Reject Counter Offer
                            </button>
                        </form>
                        
                    <?php elseif ($is_proposed): ?>
                        <button class="btn btn-outline" disabled>
                            <i class="fas fa-hourglass-half"></i> Waiting for Seller Response
                        </button>
                        
                        <button onclick="openProposeModal(<?php echo $listing['id']; ?>, <?php echo $listing['price']; ?>)" class="btn btn-primary">
                            <i class="fas fa-edit"></i> Modify Offer
                        </button>
                    <?php endif; ?>
                    
                    <a href="/broker_system/user/product.php?id=<?php echo $listing['id']; ?>" target="_blank" class="btn btn-outline">
                        <i class="fas fa-eye"></i> View Listing
                    </a>
                </div>
            </div>
        <?php endwhile; ?>
    <?php else: ?>
        <div class="empty-state">
            <div class="empty-state-icon">
                <i class="fas fa-check-circle"></i>
            </div>
            <h3>No Pending Listings</h3>
            <p>All listings have been processed.</p>
        </div>
    <?php endif; ?>
    
    <!-- Listings Awaiting Payment -->
    <?php if ($paymentNeeded && $paymentNeeded->num_rows > 0): ?>
    <h2 style="margin: 2rem 0 1rem; font-size: 1.25rem;">Listings Awaiting Payment</h2>
    
    <?php while($listing = $paymentNeeded->fetch_assoc()): ?>
        <div class="listing-card">
            <div class="card-header">
                <div class="listing-info">
                    <h3><?php echo htmlspecialchars($listing['title']); ?></h3>
                    <div class="listing-meta">
                        <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($listing['seller_name']); ?></span>
                        <span><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($listing['seller_email']); ?></span>
                        <span class="badge badge-payment"><i class="fas fa-credit-card"></i> Awaiting Payment</span>
                    </div>
                </div>
                <div class="listing-price"><?php echo formatMoney($listing['price']); ?></div>
            </div>
            
            <?php if ($listing['proposed_commission']): ?>
            <div class="negotiation-box">
                <div class="offer-grid">
                    <div class="offer-item">
                        <span class="offer-label">Agreed Commission</span>
                        <span class="offer-value proposed"><?php echo $listing['proposed_commission']; ?>%</span>
                    </div>
                    <div class="offer-item">
                        <span class="offer-label">Deposit Required</span>
                        <span class="offer-value proposed"><?php echo formatMoney($listing['proposed_deposit']); ?></span>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="btn-group">
                <a href="/broker_system/user/pay_listing.php?listing_id=<?php echo $listing['id']; ?>" class="btn btn-success" target="_blank">
                    <i class="fas fa-credit-card"></i> Help Seller Pay
                </a>
                <a href="/broker_system/user/product.php?id=<?php echo $listing['id']; ?>" target="_blank" class="btn btn-outline">
                    <i class="fas fa-eye"></i> View Listing
                </a>
            </div>
        </div>
    <?php endwhile; ?>
    <?php endif; ?>
</div>

<!-- Propose Modal -->
<div id="proposeModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-percent"></i> Propose Commission & Deposit</h3>
            <span class="close-modal" onclick="closeModal()">&times;</span>
        </div>
        <form method="POST">
            <input type="hidden" name="listing_id" id="proposeListingId">
            <input type="hidden" name="action" value="propose_terms">
            
            <div class="form-group">
                <label>Listing Price</label>
                <input type="text" id="modalPrice" disabled style="background: #f8fafc;">
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Commission (%)</label>
                    <input type="number" name="commission_percent" id="modalCommission" step="0.5" min="1" max="20" required>
                    <div class="info-text" id="commissionHint"></div>
                </div>
                <div class="form-group">
                    <label>Deposit Amount (ETB)</label>
                    <input type="number" name="deposit_amount" id="modalDeposit" step="100" min="0" required>
                </div>
            </div>
            
            <div class="form-group">
                <label>Admin Notes (Optional)</label>
                <textarea name="admin_notes" rows="3" placeholder="Add any notes for the seller..."></textarea>
            </div>
            
            <button type="submit" name="propose_terms" class="btn btn-primary" style="width: 100%;">
                <i class="fas fa-paper-plane"></i> Send Proposal to Seller
            </button>
        </form>
    </div>
</div>

<style>
    .info-text {
        font-size: 0.7rem;
        color: var(--gray);
        margin-top: 0.25rem;
    }
    h2 {
        font-size: 1.1rem;
        font-weight: 600;
        color: var(--dark);
        margin-bottom: 1rem;
    }
</style>

<script>
function openProposeModal(listingId, price) {
    document.getElementById('proposeListingId').value = listingId;
    document.getElementById('modalPrice').value = formatMoney(price);
    
    let recommendedCommission = 5;
    if (price > 2000000) recommendedCommission = 3;
    else if (price >= 500000) recommendedCommission = 5;
    else recommendedCommission = 7;
    
    document.getElementById('modalCommission').value = recommendedCommission;
    document.getElementById('commissionHint').innerHTML = `<i class="fas fa-robot"></i> AI Recommendation: ${recommendedCommission}% based on listing value`;
    
    let recommendedDeposit = price * 0.25;
    if (recommendedDeposit > 50000) recommendedDeposit = 50000;
    document.getElementById('modalDeposit').value = Math.round(recommendedDeposit);
    
    document.getElementById('proposeModal').style.display = 'flex';
}

function closeModal() {
    document.getElementById('proposeModal').style.display = 'none';
}

function formatMoney(amount) {
    return new Intl.NumberFormat('en-US', { minimumFractionDigits: 2 }).format(amount) + ' ETB';
}

window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
}
</script>

<?php
$content = ob_get_clean();
include 'layout.php';
?>