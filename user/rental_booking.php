<?php
// user/rental_booking.php - Complete Rental Booking Form

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireLogin();

$page_title = 'Book Property';
ob_start();

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];
$listing_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Get property details
$property = $conn->query("
    SELECT l.*, u.full_name as owner_name, u.id as owner_id, u.email as owner_email
    FROM listings l
    JOIN users u ON l.seller_id = u.id
    WHERE l.id = $listing_id AND l.type = 'rental' AND l.status = 'active' AND l.approval_status = 'approved'
")->fetch_assoc();

if (!$property) {
    header('Location: browse.php');
    exit;
}

// Get user info
$user = $conn->query("SELECT full_name, phone, email FROM users WHERE id = $user_id")->fetch_assoc();

// Calculate deposit and commission percentages
$depositPercent = $property['admin_deposit_percent'] ?? 30;
$commissionPercent = $property['admin_commission_percent'] ?? 15;

$conn->close();
?>

<style>
    .booking-container {
        max-width: 1200px;
        margin: 0 auto;
        display: grid;
        grid-template-columns: 1fr 420px;
        gap: 32px;
    }
    
    .property-card {
        background: white;
        border-radius: 24px;
        overflow: hidden;
        box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    }
    
    .property-image {
        width: 100%;
        height: 320px;
        object-fit: cover;
    }
    
    .property-info {
        padding: 28px;
    }
    
    .property-title {
        font-size: 24px;
        font-weight: 700;
        color: #1e293b;
        margin-bottom: 8px;
    }
    
    .property-location {
        color: #64748b;
        margin-bottom: 16px;
        font-size: 14px;
    }
    
    .property-description {
        color: #475569;
        line-height: 1.6;
        margin-top: 16px;
    }
    
    .property-features {
        display: flex;
        gap: 20px;
        margin: 20px 0;
        padding: 16px;
        background: #f8fafc;
        border-radius: 16px;
    }
    
    .feature {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 13px;
        color: #475569;
    }
    
    .booking-form {
        background: white;
        border-radius: 24px;
        padding: 28px;
        position: sticky;
        top: 20px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    }
    
    .price {
        font-size: 32px;
        font-weight: 800;
        color: #667eea;
        margin-bottom: 20px;
        padding-bottom: 16px;
        border-bottom: 2px solid #e2e8f0;
    }
    
    .price small {
        font-size: 14px;
        font-weight: normal;
        color: #64748b;
    }
    
    .form-group {
        margin-bottom: 20px;
    }
    
    .form-group label {
        display: block;
        margin-bottom: 8px;
        font-weight: 600;
        color: #334155;
        font-size: 13px;
    }
    
    .form-group label i {
        margin-right: 6px;
        color: #667eea;
    }
    
    .form-group input, .form-group textarea, .form-group select {
        width: 100%;
        padding: 12px 16px;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        font-size: 14px;
        font-family: inherit;
        transition: all 0.3s;
    }
    
    .form-group input:focus, .form-group textarea:focus {
        outline: none;
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
    }
    
    .date-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px;
    }
    
    .price-breakdown {
        background: #f8fafc;
        border-radius: 16px;
        padding: 16px;
        margin: 20px 0;
    }
    
    .breakdown-item {
        display: flex;
        justify-content: space-between;
        padding: 10px 0;
        border-bottom: 1px solid #e2e8f0;
    }
    
    .breakdown-item:last-child {
        border-bottom: none;
    }
    
    .breakdown-item.total {
        font-weight: 700;
        font-size: 16px;
        color: #667eea;
        border-top: 2px solid #e2e8f0;
        margin-top: 8px;
        padding-top: 12px;
    }
    
    .btn-book {
        width: 100%;
        padding: 14px;
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: white;
        border: none;
        border-radius: 40px;
        font-size: 16px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
        margin-top: 20px;
    }
    
    .btn-book:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(102,126,234,0.4);
    }
    
    .btn-book:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }
    
    .info-note {
        background: #dbeafe;
        border-radius: 12px;
        padding: 12px;
        margin-top: 16px;
        font-size: 12px;
        color: #1e40af;
        text-align: center;
    }
    
    @media (max-width: 768px) {
        .booking-container {
            grid-template-columns: 1fr;
        }
        .booking-form {
            position: static;
        }
        .date-row {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="booking-container">
    <!-- Property Details -->
    <div class="property-card">
        <?php 
        $cover_image = $property['cover_image'] && file_exists('../uploads/listings/' . $property['cover_image']) 
            ? '/broker_system/uploads/listings/' . $property['cover_image'] 
            : '';
        ?>
        <img src="<?php echo $cover_image ?: 'https://via.placeholder.com/800x400?text=Property+Image'; ?>" class="property-image">
        <div class="property-info">
            <h1 class="property-title"><?php echo htmlspecialchars($property['title']); ?></h1>
            <div class="property-location">
                <i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($property['location'] ?: 'Location not specified'); ?>
            </div>
            
            <?php 
            $additional = $property['additional_details'] ? json_decode($property['additional_details'], true) : [];
            if (!empty($additional)): 
            ?>
            <div class="property-features">
                <?php if (!empty($additional['bedrooms'])): ?>
                <div class="feature"><i class="fas fa-bed"></i> <?php echo $additional['bedrooms']; ?> beds</div>
                <?php endif; ?>
                <?php if (!empty($additional['bathrooms'])): ?>
                <div class="feature"><i class="fas fa-bath"></i> <?php echo $additional['bathrooms']; ?> baths</div>
                <?php endif; ?>
                <?php if (!empty($additional['area'])): ?>
                <div class="feature"><i class="fas fa-arrows-alt"></i> <?php echo $additional['area']; ?> sqm</div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <div class="property-description">
                <h3 style="margin-bottom: 12px; font-size: 18px;">Description</h3>
                <p><?php echo nl2br(htmlspecialchars($property['description'])); ?></p>
            </div>
        </div>
    </div>
    
    <!-- Booking Form -->
    <div class="booking-form">
        <div class="price">
            <?php echo formatMoney($property['price']); ?><small>/night</small>
        </div>
        
        <form method="POST" action="initiate_rental.php" id="bookingForm">
            <input type="hidden" name="listing_id" value="<?php echo $listing_id; ?>">
            
            <div class="date-row">
                <div class="form-group">
                    <label><i class="fas fa-calendar-check"></i> Check-in</label>
                    <input type="date" name="check_in" id="check_in" required min="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-calendar-times"></i> Check-out</label>
                    <input type="date" name="check_out" id="check_out" required>
                </div>
            </div>
            
            <div class="form-group">
                <label><i class="fas fa-users"></i> Guests</label>
                <input type="number" name="guests" min="1" max="20" value="2" required>
            </div>
            
            <div class="form-group">
                <label><i class="fas fa-user"></i> Full Name</label>
                <input type="text" name="guest_name" value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
            </div>
            
            <div class="form-group">
                <label><i class="fas fa-phone"></i> Phone Number</label>
                <input type="tel" name="phone" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" placeholder="+251XXXXXXXXX">
            </div>
            
            <div class="form-group">
                <label><i class="fas fa-comment"></i> Special Requests</label>
                <textarea name="message" rows="3" placeholder="Any special requests or questions for the owner?"></textarea>
            </div>
            
            <div class="price-breakdown" id="priceBreakdown">
                <div class="breakdown-item">
                    <span>₿ Price per night</span>
                    <span><?php echo formatMoney($property['price']); ?></span>
                </div>
                <div class="breakdown-item" id="nightsRow" style="display: none;">
                    <span><span id="nightsCount">0</span> nights</span>
                    <span id="nightsTotal"><?php echo formatMoney(0); ?></span>
                </div>
                <div class="breakdown-item">
                    <span>Deposit (<?php echo $depositPercent; ?>%)</span>
                    <span id="depositAmount"><?php echo formatMoney(0); ?></span>
                </div>
                <div class="breakdown-item">
                    <span>Service Fee (<?php echo $commissionPercent; ?>%)</span>
                    <span id="feeAmount"><?php echo formatMoney(0); ?></span>
                </div>
                <div class="breakdown-item total">
                    <span>Total to Pay Today</span>
                    <span id="totalAmount"><?php echo formatMoney(0); ?></span>
                </div>
            </div>
            
            <div class="info-note">
                <i class="fas fa-shield-alt"></i> Your payment is protected by escrow. 
                Deposit refunded if owner cancels.
            </div>
            
            <button type="submit" class="btn-book" id="bookBtn">
                <i class="fas fa-credit-card"></i> Continue to Payment
            </button>
        </form>
    </div>
</div>

<script>
const pricePerNight = <?php echo $property['price']; ?>;
const depositPercent = <?php echo $depositPercent; ?>;
const commissionPercent = <?php echo $commissionPercent; ?>;

const checkInInput = document.getElementById('check_in');
const checkOutInput = document.getElementById('check_out');
const bookBtn = document.getElementById('bookBtn');

function calculatePrice() {
    const checkIn = checkInInput.value;
    const checkOut = checkOutInput.value;
    
    if (checkIn && checkOut) {
        const start = new Date(checkIn);
        const end = new Date(checkOut);
        const nights = Math.ceil((end - start) / (1000 * 60 * 60 * 24));
        
        if (nights > 0) {
            const totalRent = pricePerNight * nights;
            const deposit = totalRent * (depositPercent / 100);
            const fee = totalRent * (commissionPercent / 100);
            const total = deposit + fee;
            
            document.getElementById('nightsRow').style.display = 'flex';
            document.getElementById('nightsCount').textContent = nights;
            document.getElementById('nightsTotal').textContent = formatMoney(totalRent);
            document.getElementById('depositAmount').textContent = formatMoney(deposit);
            document.getElementById('feeAmount').textContent = formatMoney(fee);
            document.getElementById('totalAmount').textContent = formatMoney(total);
            return true;
        }
    }
    return false;
}

function formatMoney(amount) {
    return new Intl.NumberFormat('en-US', { 
        minimumFractionDigits: 2, 
        maximumFractionDigits: 2 
    }).format(amount) + ' ETB';
}

checkInInput.addEventListener('change', function() {
    const minDate = new Date(this.value);
    minDate.setDate(minDate.getDate() + 1);
    checkOutInput.min = minDate.toISOString().split('T')[0];
    calculatePrice();
});

checkOutInput.addEventListener('change', calculatePrice);

// Set min date for check-in
const today = new Date().toISOString().split('T')[0];
checkInInput.min = today;

// Disable booking button if dates not selected
function validateForm() {
    const checkIn = checkInInput.value;
    const checkOut = checkOutInput.value;
    
    if (!checkIn || !checkOut) {
        bookBtn.disabled = true;
        bookBtn.title = 'Please select check-in and check-out dates';
    } else {
        const start = new Date(checkIn);
        const end = new Date(checkOut);
        const nights = Math.ceil((end - start) / (1000 * 60 * 60 * 24));
        if (nights > 0) {
            bookBtn.disabled = false;
        } else {
            bookBtn.disabled = true;
        }
    }
}

checkInInput.addEventListener('change', validateForm);
checkOutInput.addEventListener('change', validateForm);
validateForm();
</script>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>