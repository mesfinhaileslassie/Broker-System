<?php
// ============================================
// FILE: user/rental_booking.php
// Description: Complete Rental Booking Form with Availability Checking
// ============================================

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/AvailabilityManager.php';

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

// Initialize availability manager
$availabilityManager = new AvailabilityManager($conn);

// Check if property is generally available
$is_generally_available = ($property['availability_status'] == 'available');

// Get user info
$user = $conn->query("SELECT full_name, phone, email FROM users WHERE id = $user_id")->fetch_assoc();

// Calculate deposit and commission percentages
$depositPercent = $property['admin_deposit_percent'] ?? 30;
$commissionPercent = $property['admin_commission_percent'] ?? 15;

// Get blocked dates for calendar display
$blocked_dates = [];
$reservations = $conn->query("
    SELECT check_in_date, check_out_date 
    FROM reservation_records 
    WHERE listing_id = $listing_id 
    AND status IN ('reserved', 'active')
");
while ($res = $reservations->fetch_assoc()) {
    $current = strtotime($res['check_in_date']);
    $end = strtotime($res['check_out_date']);
    while ($current < $end) {
        $blocked_dates[] = date('Y-m-d', $current);
        $current = strtotime('+1 day', $current);
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book <?php echo htmlspecialchars($property['title']); ?> - Ethio Brokerplace</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <style>
        :root {
            --primary: #667eea;
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
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; }
        
        .header {
            background: white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            padding: 16px 24px;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        
        .header-content {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }
        
        .logo {
            font-size: 24px;
            font-weight: 800;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            text-decoration: none;
        }
        
        .back-btn {
            background: var(--light);
            padding: 8px 20px;
            border-radius: 40px;
            text-decoration: none;
            color: var(--gray);
            font-weight: 500;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .back-btn:hover {
            background: var(--primary);
            color: white;
        }
        
        .booking-container {
            max-width: 1400px;
            margin: 40px auto;
            padding: 0 24px;
            display: grid;
            grid-template-columns: 1fr 420px;
            gap: 32px;
        }
        
        .property-card {
            background: white;
            border-radius: 28px;
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
            flex-wrap: wrap;
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
            border-radius: 28px;
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
            padding: 16px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border: none;
            border-radius: 50px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            margin-top: 20px;
        }
        
        .btn-book:hover:not(:disabled) {
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
        
        .alert {
            padding: 12px 16px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border-left: 4px solid #10b981;
        }
        
        .alert-danger {
            background: #fee2e2;
            color: #991b1b;
            border-left: 4px solid #ef4444;
        }
        
        .alert-warning {
            background: #fed7aa;
            color: #9a3412;
            border-left: 4px solid #f59e0b;
        }
        
        .availability-loading {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px;
            background: #f1f5f9;
            border-radius: 12px;
            margin: 12px 0;
        }
        
        .spinner {
            width: 20px;
            height: 20px;
            border: 2px solid #e2e8f0;
            border-top-color: #667eea;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        @media (max-width: 968px) {
            .booking-container {
                grid-template-columns: 1fr;
            }
            .booking-form {
                position: static;
            }
        }
        
        @media (max-width: 640px) {
            .booking-container {
                padding: 0 16px;
                margin: 20px auto;
            }
            .property-title {
                font-size: 20px;
            }
            .price {
                font-size: 28px;
            }
            .property-info {
                padding: 20px;
            }
            .date-row {
                grid-template-columns: 1fr;
            }
            .property-features {
                flex-direction: column;
                gap: 10px;
            }
        }
        
        /* Flatpickr customization */
        .flatpickr-day.disabled, .flatpickr-day.disabled:hover {
            background: #fee2e2 !important;
            color: #dc2626 !important;
            text-decoration: line-through;
            cursor: not-allowed;
        }
        
        .flatpickr-day.inRange, .flatpickr-day.prevMonthDay.inRange, .flatpickr-day.nextMonthDay.inRange {
            background: #dbeafe !important;
            border-color: #dbeafe !important;
        }
        
        .flatpickr-day.selected, .flatpickr-day.selected:hover {
            background: #667eea !important;
            border-color: #667eea !important;
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <a href="/broker_system/index.php" class="logo">
                <i class="fas fa-store"></i> Ethio Brokerplace
            </a>
            <a href="browse.php?type=rental" class="back-btn">
                <i class="fas fa-arrow-left"></i> Back to Rentals
            </a>
        </div>
    </header>
    
    <div class="booking-container">
        <!-- Property Details -->
        <div class="property-card">
            <?php 
            $cover_image = $property['cover_image'] && file_exists('../uploads/listings/' . $property['cover_image']) 
                ? '/broker_system/uploads/listings/' . $property['cover_image'] 
                : '';
            ?>
            <img src="<?php echo $cover_image ?: 'https://via.placeholder.com/800x400?text=Property+Image'; ?>" class="property-image" onerror="this.src='https://via.placeholder.com/800x400?text=No+Image'">
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
                    <div class="feature"><i class="fas fa-bed"></i> <?php echo $additional['bedrooms']; ?> bedrooms</div>
                    <?php endif; ?>
                    <?php if (!empty($additional['bathrooms'])): ?>
                    <div class="feature"><i class="fas fa-bath"></i> <?php echo $additional['bathrooms']; ?> bathrooms</div>
                    <?php endif; ?>
                    <?php if (!empty($additional['area'])): ?>
                    <div class="feature"><i class="fas fa-arrows-alt"></i> <?php echo $additional['area']; ?> sqm</div>
                    <?php endif; ?>
                    <?php if (!empty($additional['parking'])): ?>
                    <div class="feature"><i class="fas fa-car"></i> Parking available</div>
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
            
            <?php if (!$is_generally_available): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-ban"></i>
                    <div>This property is currently <strong><?php echo ucfirst($property['availability_status']); ?></strong> and cannot be booked.</div>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="initiate_rental.php" id="bookingForm">
                <input type="hidden" name="listing_id" value="<?php echo $listing_id; ?>">
                
                <div class="date-row">
                    <div class="form-group">
                        <label><i class="fas fa-calendar-check"></i> Check-in Date</label>
                        <input type="text" name="check_in" id="check_in" 
                               placeholder="Select check-in date" 
                               <?php echo !$is_generally_available ? 'disabled' : ''; ?>
                               required>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-calendar-times"></i> Check-out Date</label>
                        <input type="text" name="check_out" id="check_out" 
                               placeholder="Select check-out date" 
                               <?php echo !$is_generally_available ? 'disabled' : ''; ?>
                               required>
                    </div>
                </div>
                
                <!-- Availability Message Container -->
                <div id="availabilityMessage" style="display: none;"></div>
                <div id="availabilityLoading" class="availability-loading" style="display: none;">
                    <div class="spinner"></div>
                    <span>Checking availability...</span>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-users"></i> Number of Guests</label>
                    <input type="number" name="guests" id="guests" min="1" max="20" value="2" required <?php echo !$is_generally_available ? 'disabled' : ''; ?>>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-user"></i> Full Name</label>
                    <input type="text" name="guest_name" value="<?php echo htmlspecialchars($user['full_name']); ?>" required <?php echo !$is_generally_available ? 'disabled' : ''; ?>>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-phone"></i> Phone Number</label>
                    <input type="tel" name="phone" id="phone" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" placeholder="+251XXXXXXXXX" <?php echo !$is_generally_available ? 'disabled' : ''; ?>>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-envelope"></i> Email Address</label>
                    <input type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required <?php echo !$is_generally_available ? 'disabled' : ''; ?>>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-comment"></i> Special Requests (Optional)</label>
                    <textarea name="message" id="message" rows="3" placeholder="Any special requests or questions for the owner?" <?php echo !$is_generally_available ? 'disabled' : ''; ?>></textarea>
                </div>
                
                <div class="price-breakdown" id="priceBreakdown">
                    <div class="breakdown-item">
                        <span>🏠 Price per night</span>
                        <span><?php echo formatMoney($property['price']); ?></span>
                    </div>
                    <div class="breakdown-item" id="nightsRow" style="display: none;">
                        <span><span id="nightsCount">0</span> nights</span>
                        <span id="nightsTotal"><?php echo formatMoney(0); ?></span>
                    </div>
                    <div class="breakdown-item">
                        <span>💰 Deposit (<?php echo $depositPercent; ?>%)</span>
                        <span id="depositAmount"><?php echo formatMoney(0); ?></span>
                    </div>
                    <div class="breakdown-item">
                        <span>📋 Service Fee (<?php echo $commissionPercent; ?>%)</span>
                        <span id="feeAmount"><?php echo formatMoney(0); ?></span>
                    </div>
                    <div class="breakdown-item total">
                        <span>💳 Total to Pay Today</span>
                        <span id="totalAmount"><?php echo formatMoney(0); ?></span>
                    </div>
                    <div class="breakdown-item">
                        <span>⏰ Remaining Balance</span>
                        <span id="remainingAmount"><?php echo formatMoney(0); ?></span>
                    </div>
                </div>
                
                <div class="info-note">
                    <i class="fas fa-shield-alt"></i> Your payment is protected by escrow. 
                    Deposit is fully refundable if the owner cancels. The remaining balance is paid at check-in.
                </div>
                
                <button type="submit" class="btn-book" id="bookBtn" disabled>
                    <i class="fas fa-credit-card"></i> Proceed to Payment
                </button>
            </form>
        </div>
    </div>
    
    <script>
    // Configuration
    const pricePerNight = <?php echo $property['price']; ?>;
    const depositPercent = <?php echo $depositPercent; ?>;
    const commissionPercent = <?php echo $commissionPercent; ?>;
    const listingId = <?php echo $listing_id; ?>;
    const blockedDates = <?php echo json_encode($blocked_dates); ?>;
    
    // DOM Elements
    const checkInInput = document.getElementById('check_in');
    const checkOutInput = document.getElementById('check_out');
    const bookBtn = document.getElementById('bookBtn');
    const availMsgDiv = document.getElementById('availabilityMessage');
    const availLoadingDiv = document.getElementById('availabilityLoading');
    const guestsInput = document.getElementById('guests');
    const phoneInput = document.getElementById('phone');
    const messageInput = document.getElementById('message');
    
    let isCheckingAvailability = false;
    let lastCheckedDates = { check_in: null, check_out: null };
    
    // Format money helper
    function formatMoney(amount) {
        return new Intl.NumberFormat('en-US', { 
            minimumFractionDigits: 2, 
            maximumFractionDigits: 2 
        }).format(amount) + ' ETB';
    }
    
    // Calculate price breakdown
    function calculatePrice(checkIn, checkOut) {
        if (!checkIn || !checkOut) return false;
        
        const start = new Date(checkIn);
        const end = new Date(checkOut);
        const nights = Math.ceil((end - start) / (1000 * 60 * 60 * 24));
        
        if (nights > 0) {
            const totalRent = pricePerNight * nights;
            const deposit = totalRent * (depositPercent / 100);
            const fee = totalRent * (commissionPercent / 100);
            const total = deposit + fee;
            const remaining = totalRent - deposit;
            
            document.getElementById('nightsRow').style.display = 'flex';
            document.getElementById('nightsCount').textContent = nights;
            document.getElementById('nightsTotal').textContent = formatMoney(totalRent);
            document.getElementById('depositAmount').textContent = formatMoney(deposit);
            document.getElementById('feeAmount').textContent = formatMoney(fee);
            document.getElementById('totalAmount').textContent = formatMoney(total);
            document.getElementById('remainingAmount').textContent = formatMoney(remaining);
            return { nights, totalRent, deposit, fee, total, remaining };
        }
        return false;
    }
    
    // Check availability via AJAX
    async function checkAvailability() {
        const checkIn = checkInInput?.value;
        const checkOut = checkOutInput?.value;
        
        if (!checkIn || !checkOut) {
            if (availMsgDiv) availMsgDiv.style.display = 'none';
            if (bookBtn) bookBtn.disabled = true;
            return false;
        }
        
        // Don't re-check if same dates
        if (lastCheckedDates.check_in === checkIn && lastCheckedDates.check_out === checkOut) {
            return true;
        }
        
        isCheckingAvailability = true;
        if (availLoadingDiv) availLoadingDiv.style.display = 'flex';
        if (availMsgDiv) availMsgDiv.style.display = 'none';
        
        try {
            const response = await fetch('/broker_system/api/check_availability.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ 
                    listing_id: listingId, 
                    check_in: checkIn, 
                    check_out: checkOut 
                })
            });
            
            const text = await response.text();
            let data;
            try {
                data = JSON.parse(text);
            } catch (e) {
                console.error('JSON parse error:', text);
                throw new Error('Invalid server response');
            }
            
            lastCheckedDates = { check_in: checkIn, check_out: checkOut };
            
            if (availMsgDiv) {
                availMsgDiv.style.display = 'block';
                if (data.available) {
                    availMsgDiv.className = 'alert alert-success';
                    availMsgDiv.innerHTML = '<i class="fas fa-check-circle"></i> ✓ <strong>Available!</strong> This property is available for your selected dates.';
                    if (bookBtn) bookBtn.disabled = false;
                } else {
                    availMsgDiv.className = 'alert alert-danger';
                    availMsgDiv.innerHTML = '<i class="fas fa-times-circle"></i> ✗ <strong>Not Available</strong> ' + (data.message || 'This property is already booked for some of your selected dates.');
                    if (bookBtn) bookBtn.disabled = true;
                }
            }
            
            return data.available;
            
        } catch (error) {
            console.error('Availability check failed:', error);
            if (availMsgDiv) {
                availMsgDiv.style.display = 'block';
                availMsgDiv.className = 'alert alert-danger';
                availMsgDiv.innerHTML = '<i class="fas fa-exclamation-triangle"></i> ⚠️ Unable to check availability. Please try again.';
            }
            if (bookBtn) bookBtn.disabled = true;
            return false;
        } finally {
            isCheckingAvailability = false;
            if (availLoadingDiv) availLoadingDiv.style.display = 'none';
        }
    }
    
    // Validate phone number (Ethiopian format)
    function validatePhone(phone) {
        if (!phone) return true;
        const phoneRegex = /^(\+251|0)[0-9]{9}$/;
        return phoneRegex.test(phone);
    }
    
    // Form validation before submit
    function validateForm() {
        const checkIn = checkInInput?.value;
        const checkOut = checkOutInput?.value;
        const guests = guestsInput?.value;
        const phone = phoneInput?.value;
        
        if (!checkIn || !checkOut) {
            alert('Please select check-in and check-out dates');
            return false;
        }
        
        const nights = Math.ceil((new Date(checkOut) - new Date(checkIn)) / (1000 * 60 * 60 * 24));
        if (nights <= 0) {
            alert('Check-out date must be after check-in date');
            return false;
        }
        
        if (nights > 365) {
            alert('Maximum booking period is 365 nights');
            return false;
        }
        
        if (!guests || guests < 1) {
            alert('Please enter number of guests');
            return false;
        }
        
        if (phone && !validatePhone(phone)) {
            alert('Please enter a valid Ethiopian phone number (format: 0912345678 or +251912345678)');
            return false;
        }
        
        return true;
    }
    
    // Initialize Flatpickr date pickers
    if (checkInInput && checkOutInput) {
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        
        const maxDate = new Date();
        maxDate.setFullYear(maxDate.getFullYear() + 1);
        
        // Configure flatpickr for check-in
        const checkInPicker = flatpickr(checkInInput, {
            dateFormat: "Y-m-d",
            minDate: today,
            maxDate: maxDate,
            disable: blockedDates,
            onChange: function(selectedDates, dateStr, instance) {
                if (dateStr) {
                    // Update check-out min date
                    const minCheckOut = new Date(dateStr);
                    minCheckOut.setDate(minCheckOut.getDate() + 1);
                    checkOutPicker.set('minDate', minCheckOut);
                    
                    // Clear check-out if invalid
                    if (checkOutInput.value && new Date(checkOutInput.value) <= minCheckOut) {
                        checkOutPicker.clear();
                    }
                    
                    // Recalculate price
                    calculatePrice(checkInInput.value, checkOutInput.value);
                    
                    // Check availability
                    checkAvailability();
                }
            }
        });
        
        // Configure flatpickr for check-out
        const checkOutPicker = flatpickr(checkOutInput, {
            dateFormat: "Y-m-d",
            minDate: today,
            maxDate: maxDate,
            disable: blockedDates,
            onChange: function(selectedDates, dateStr, instance) {
                if (dateStr) {
                    calculatePrice(checkInInput.value, checkOutInput.value);
                    checkAvailability();
                }
            }
        });
    }
    
    // Real-time price calculation when guests change
    if (guestsInput) {
        guestsInput.addEventListener('change', function() {
            calculatePrice(checkInInput?.value, checkOutInput?.value);
        });
    }
    
    // Form submit handler
    const bookingForm = document.getElementById('bookingForm');
    if (bookingForm) {
        bookingForm.addEventListener('submit', function(e) {
            if (!validateForm()) {
                e.preventDefault();
                return false;
            }
            
            // Disable button to prevent double submission
            const submitBtn = document.getElementById('bookBtn');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            }
            
            return true;
        });
    }
    
    // Initial calculation and availability check if dates are pre-filled
    if (checkInInput?.value && checkOutInput?.value) {
        calculatePrice(checkInInput.value, checkOutInput.value);
        checkAvailability();
    }
    
    // Set min date for check-in
    const todayStr = new Date().toISOString().split('T')[0];
    if (checkInInput && !checkInInput.value) {
        checkInInput.setAttribute('min', todayStr);
    }
    </script>
</body>
</html>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>