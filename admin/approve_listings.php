<?php
// admin/approve_listings.php - Add Negotiation Buttons to Existing Page

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
        
        // Get or create negotiation
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
        
        // Add notification for seller
        $listing = $conn->query("SELECT title, seller_id FROM listings WHERE id = $listing_id")->fetch_assoc();
        $notif_stmt = $conn->prepare("
            INSERT INTO notifications (user_id, title, message, created_at) 
            VALUES (?, 'Commission Proposal', 'Admin has proposed {$commission}% commission and " . formatMoney($deposit) . " deposit for your listing \"{$listing['title']}\". Please review.', NOW())
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
        
        // Get seller info
        $neg = $conn->query("SELECT seller_id, listing_id FROM listing_negotiations WHERE id = $negotiation_id")->fetch_assoc();
        $listing = $conn->query("SELECT title FROM listings WHERE id = {$neg['listing_id']}")->fetch_assoc();
        
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

// Get pending listings with negotiation status
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

// Get statistics
$stats = [
    'pending' => $conn->query("SELECT COUNT(*) as count FROM listings WHERE approval_status = 'pending'")->fetch_assoc()['count'],
    'negotiating' => $conn->query("SELECT COUNT(*) as count FROM listing_negotiations WHERE status IN ('commission_proposed', 'counter_offer_sent')")->fetch_assoc()['count'],
    'pending_payment' => $conn->query("SELECT COUNT(*) as count FROM listing_negotiations WHERE status = 'agreement_accepted'")->fetch_assoc()['count'],
];

$conn->close();
?>

<style>
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 20px;
        margin-bottom: 28px;
    }
    .stat-card {
        background: white;
        border-radius: 20px;
        padding: 24px;
        text-align: center;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    .stat-value { font-size: 32px; font-weight: 700; color: #0f172a; }
    .stat-label { font-size: 13px; color: #64748b; margin-top: 6px; }
    
    .listing-card {
        background: white;
        border-radius: 20px;
        padding: 24px;
        margin-bottom: 20px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    .listing-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        flex-wrap: wrap;
        margin-bottom: 16px;
    }
    .listing-title { font-size: 18px; font-weight: 700; color: #0f172a; }
    .listing-price { font-size: 20px; font-weight: 700; color: #667eea; }
    .seller-info { font-size: 12px; color: #64748b; margin-top: 4px; }
    
    .negotiation-box {
        background: #f8fafc;
        border-radius: 16px;
        padding: 16px;
        margin: 16px 0;
    }
    .offer-row {
        display: flex;
        gap: 20px;
        flex-wrap: wrap;
        margin-bottom: 12px;
    }
    .offer-label { font-size: 11px; color: #64748b; }
    .offer-value { font-size: 16px; font-weight: 700; }
    .offer-value.proposed { color: #667eea; }
    .offer-value.counter { color: #f59e0b; }
    
    .counter-box {
        background: #fef3c7;
        padding: 12px;
        border-radius: 12px;
        margin: 12px 0;
    }
    
    .btn-group {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
        margin-top: 16px;
    }
    .btn {
        padding: 10px 20px;
        border-radius: 40px;
        font-weight: 600;
        font-size: 13px;
        cursor: pointer;
        border: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        text-decoration: none;
    }
    .btn-primary { background: linear-gradient(135deg, #667eea, #764ba2); color: white; }
    .btn-success { background: #10b981; color: white; }
    .btn-warning { background: #f59e0b; color: white; }
    .btn-danger { background: #ef4444; color: white; }
    .btn-outline { background: transparent; border: 1px solid #e2e8f0; color: #64748b; }
    .btn-sm { padding: 6px 12px; font-size: 12px; }
    
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
        border-radius: 24px;
        padding: 28px;
        width: 500px;
        max-width: 90%;
    }
    .form-group { margin-bottom: 16px; }
    .form-group label { display: block; margin-bottom: 6px; font-weight: 600; }
    .form-group input, .form-group textarea { width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 10px; }
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .close-modal { float: right; font-size: 24px; cursor: pointer; color: #94a3b8; }
    
    .alert { padding: 12px 16px; border-radius: 12px; margin-bottom: 20px; }
    .alert-success { background: #d1fae5; color: #059669; }
    .alert-error { background: #fee2e2; color: #dc2626; }
</style>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-value"><?php echo $stats['pending']; ?></div><div class="stat-label">Pending Approval</div></div>
    <div class="stat-card"><div class="stat-value"><?php echo $stats['negotiating']; ?></div><div class="stat-label">Under Negotiation</div></div>
    <div class="stat-card"><div class="stat-value"><?php echo $stats['pending_payment']; ?></div><div class="stat-label">Awaiting Payment</div></div>
</div>

<?php if ($message): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $message; ?></div>
<?php endif; ?>

<?php if ($pendingListings && $pendingListings->num_rows > 0): ?>
    <?php while($listing = $pendingListings->fetch_assoc()): 
        $has_negotiation = $listing['negotiation_id'];
        $is_proposed = ($listing['negotiation_status'] == 'commission_proposed');
        $has_counter = ($listing['negotiation_status'] == 'counter_offer_sent');
    ?>
        <div class="listing-card">
            <div class="listing-header">
                <div>
                    <div class="listing-title"><?php echo htmlspecialchars($listing['title']); ?></div>
                    <div class="seller-info">
                        <i class="fas fa-user"></i> <?php echo htmlspecialchars($listing['seller_name']); ?> • 
                        <i class="fas fa-envelope"></i> <?php echo htmlspecialchars($listing['seller_email']); ?>
                    </div>
                </div>
                <div class="listing-price"><?php echo formatMoney($listing['price']); ?></div>
            </div>
            
            <!-- Show current offer if exists -->
            <?php if ($listing['proposed_commission']): ?>
            <div class="negotiation-box">
                <div class="offer-row">
                    <div><span class="offer-label">Proposed Commission:</span> <span class="offer-value proposed"><?php echo $listing['proposed_commission']; ?>%</span></div>
                    <div><span class="offer-label">Proposed Deposit:</span> <span class="offer-value proposed"><?php echo formatMoney($listing['proposed_deposit']); ?></span></div>
                </div>
                
                <?php if ($listing['counter_commission']): ?>
                <div class="offer-row">
                    <div><span class="offer-label">Seller Counter:</span> <span class="offer-value counter"><?php echo $listing['counter_commission']; ?>%</span></div>
                    <div><span class="offer-label">Seller Counter Deposit:</span> <span class="offer-value counter"><?php echo formatMoney($listing['counter_deposit']); ?></span></div>
                </div>
                <?php if ($listing['counter_message']): ?>
                <div class="counter-box">
                    <i class="fas fa-quote-left"></i> <?php echo htmlspecialchars($listing['counter_message']); ?>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <!-- Action Buttons -->
            <div class="btn-group">
                <?php if (!$has_negotiation): ?>
                    <!-- No negotiation yet - Propose Terms -->
                    <button onclick="openProposeModal(<?php echo $listing['id']; ?>, <?php echo $listing['price']; ?>)" class="btn btn-primary">
                        <i class="fas fa-percent"></i> 💰 Propose Terms
                    </button>
                    
                <?php elseif ($has_counter): ?>
                    <!-- Counter offer received - Accept/Reject -->
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="negotiation_id" value="<?php echo $listing['negotiation_id']; ?>">
                        <input type="hidden" name="action" value="accept_counter">
                        <button type="submit" name="accept_counter" class="btn btn-success" onclick="return confirm('Accept this counter offer?')">
                            <i class="fas fa-check"></i> ✅ Accept Counter Offer
                        </button>
                    </form>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="negotiation_id" value="<?php echo $listing['negotiation_id']; ?>">
                        <input type="hidden" name="action" value="reject_counter">
                        <button type="submit" name="reject_counter" class="btn btn-danger" onclick="return confirm('Reject this counter offer?')">
                            <i class="fas fa-times"></i> ❌ Reject Counter Offer
                        </button>
                    </form>
                    
                <?php elseif ($is_proposed): ?>
                    <!-- Waiting for seller response -->
                    <button class="btn btn-outline" disabled>
                        <i class="fas fa-hourglass-half"></i> ⏳ Waiting for Seller Response
                    </button>
                    
                <?php endif; ?>
                
                <!-- View Listing Button -->
                <a href="/broker_system/user/product.php?id=<?php echo $listing['id']; ?>" target="_blank" class="btn btn-outline">
                    <i class="fas fa-eye"></i> 👁️ View Listing
                </a>
            </div>
        </div>
    <?php endwhile; ?>
<?php else: ?>
    <div class="listing-card" style="text-align: center; padding: 60px;">
        <i class="fas fa-check-circle" style="font-size: 48px; color: #10b981; margin-bottom: 16px; display: block;"></i>
        <h3>No pending listings!</h3>
        <p>All listings have been processed.</p>
    </div>
<?php endif; ?>

<!-- Propose Modal -->
<div id="proposeModal" class="modal">
    <div class="modal-content">
        <span class="close-modal" onclick="closeModal()">&times;</span>
        <h3 style="margin-bottom: 20px;"><i class="fas fa-percent"></i> Propose Commission & Deposit</h3>
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
                    <small id="commissionHint" style="font-size: 11px; color: #667eea;"></small>
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

<script>
function openProposeModal(listingId, price) {
    document.getElementById('proposeListingId').value = listingId;
    document.getElementById('modalPrice').value = formatMoney(price);
    
    let recommendedCommission = 5;
    if (price > 2000000) recommendedCommission = 3;
    else if (price >= 500000) recommendedCommission = 5;
    else recommendedCommission = 7;
    
    document.getElementById('modalCommission').value = recommendedCommission;
    document.getElementById('commissionHint').innerHTML = `🤖 AI Recommendation: ${recommendedCommission}%`;
    
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