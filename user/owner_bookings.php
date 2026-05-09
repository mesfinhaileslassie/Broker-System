<?php
// user/owner_bookings.php - Complete Owner Dashboard with All Renter Info

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireLogin();

$page_title = 'My Renters';
ob_start();

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];

// Get all bookings for owner's properties with complete details
$bookings = $conn->query("
    SELECT 
        rb.*, 
        l.title as property_title, 
        l.location as property_location,
        l.price as nightly_price,
        u.full_name as tenant_name, 
        u.email as tenant_email, 
        u.phone as tenant_phone,
        u.address as tenant_address,
        u.city as tenant_city,
        t.status as transaction_status, 
        t.total_amount, 
        t.deposit_amount, 
        t.commission_amount,
        t.escrow_held,
        t.created_at as transaction_date,
        p.amount as paid_amount,
        p.confirmed_at as payment_date,
        p.telebirr_code_5digit as payment_code
    FROM rental_bookings rb
    JOIN listings l ON rb.property_id = l.id
    JOIN users u ON rb.tenant_id = u.id
    JOIN transactions t ON rb.transaction_id = t.id
    LEFT JOIN payments p ON p.transaction_id = t.id AND p.status = 'confirmed'
    WHERE rb.owner_id = $user_id
    ORDER BY rb.created_at DESC
");

// Get statistics
$stats = [
    'total' => 0,
    'pending' => 0,
    'confirmed' => 0,
    'completed' => 0,
    'paid' => 0,
    'total_earnings' => 0,
    'total_deposits' => 0
];

$bookings_list = [];
if ($bookings && $bookings->num_rows > 0) {
    while ($row = $bookings->fetch_assoc()) {
        $bookings_list[] = $row;
        $stats['total']++;
        
        if ($row['status'] == 'pending') $stats['pending']++;
        if ($row['status'] == 'confirmed') $stats['confirmed']++;
        if ($row['status'] == 'completed') $stats['completed']++;
        if ($row['paid_amount'] > 0) $stats['paid']++;
        
        $stats['total_deposits'] += $row['deposit_paid'];
        if ($row['status'] == 'completed') {
            $stats['total_earnings'] += $row['total_amount'] - $row['commission_amount'];
        }
    }
}

$conn->close();
?>

<style>
    :root {
        --primary: #667eea;
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
    
    .renters-container {
        max-width: 1400px;
        margin: 0 auto;
    }
    
    /* Header */
    .page-header {
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        border-radius: 28px;
        padding: 32px;
        margin-bottom: 28px;
        color: white;
        position: relative;
        overflow: hidden;
    }
    
    .page-header::before {
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
    
    .page-header h1 {
        position: relative;
        z-index: 1;
        font-size: 28px;
        font-weight: 700;
        margin-bottom: 8px;
    }
    
    .page-header p {
        position: relative;
        z-index: 1;
        font-size: 14px;
        opacity: 0.9;
    }
    
    /* Stats Grid */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(5, 1fr);
        gap: 20px;
        margin-bottom: 28px;
    }
    
    .stat-card {
        background: white;
        border-radius: 20px;
        padding: 20px;
        text-align: center;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        transition: all 0.3s;
        border: 1px solid var(--border);
    }
    
    .stat-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 24px -8px rgba(0,0,0,0.15);
    }
    
    .stat-value {
        font-size: 28px;
        font-weight: 700;
        color: var(--dark);
    }
    
    .stat-label {
        font-size: 11px;
        color: var(--gray);
        margin-top: 4px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    /* Booking Cards */
    .booking-card {
        background: white;
        border-radius: 24px;
        margin-bottom: 24px;
        overflow: hidden;
        box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        transition: all 0.3s;
        border: 1px solid var(--border);
    }
    
    .booking-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 30px rgba(0,0,0,0.15);
    }
    
    .booking-header {
        padding: 16px 24px;
        background: var(--light);
        border-bottom: 1px solid var(--border);
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 12px;
    }
    
    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
    }
    
    .status-pending { background: #fed7aa; color: #ea580c; }
    .status-confirmed { background: #d1fae5; color: #059669; }
    .status-completed { background: #dbeafe; color: #1e40af; }
    .status-cancelled { background: #fee2e2; color: #dc2626; }
    
    .payment-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
    }
    
    .payment-paid { background: #d1fae5; color: #059669; }
    .payment-pending { background: #fed7aa; color: #ea580c; }
    
    .booking-body {
        padding: 24px;
    }
    
    /* Property Section */
    .property-section {
        margin-bottom: 24px;
        padding-bottom: 16px;
        border-bottom: 2px solid var(--border);
    }
    
    .property-title {
        font-size: 20px;
        font-weight: 700;
        color: var(--dark);
        margin-bottom: 8px;
    }
    
    .property-location {
        color: var(--gray);
        font-size: 13px;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    
    /* Info Grid */
    .info-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 24px;
        margin-bottom: 24px;
    }
    
    .info-card {
        background: var(--light);
        border-radius: 16px;
        padding: 16px;
    }
    
    .info-card-title {
        font-size: 12px;
        font-weight: 600;
        color: var(--gray);
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 12px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .info-card-title i {
        color: var(--primary);
    }
    
    .info-row {
        margin-bottom: 10px;
    }
    
    .info-label {
        font-size: 11px;
        color: var(--gray);
        display: block;
    }
    
    .info-value {
        font-size: 14px;
        font-weight: 600;
        color: var(--dark);
        margin-top: 2px;
    }
    
    .amount-large {
        font-size: 24px;
        font-weight: 700;
        color: var(--primary);
    }
    
    /* Dates Section */
    .dates-section {
        background: linear-gradient(135deg, #667eea10, #764ba210);
        border-radius: 16px;
        padding: 16px;
        margin-bottom: 24px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 16px;
    }
    
    .date-item {
        text-align: center;
        flex: 1;
    }
    
    .date-label {
        font-size: 11px;
        color: var(--gray);
    }
    
    .date-value {
        font-size: 16px;
        font-weight: 700;
        color: var(--dark);
    }
    
    .nights-count {
        background: var(--primary);
        color: white;
        padding: 8px 20px;
        border-radius: 40px;
        font-size: 14px;
        font-weight: 600;
    }
    
    /* Special Requests */
    .special-requests {
        background: #fef3c7;
        border-radius: 12px;
        padding: 12px 16px;
        margin-bottom: 20px;
        font-size: 13px;
    }
    
    /* Action Buttons */
    .action-buttons {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
        margin-top: 20px;
        padding-top: 20px;
        border-top: 1px solid var(--border);
    }
    
    .btn {
        padding: 10px 20px;
        border-radius: 40px;
        font-weight: 600;
        font-size: 13px;
        cursor: pointer;
        transition: all 0.3s;
        border: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        text-decoration: none;
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
    
    /* Alert Banner for New Bookings */
    .alert-banner {
        background: #fef3c7;
        border-left: 4px solid #f59e0b;
        border-radius: 12px;
        padding: 16px;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 12px;
    }
    
    .alert-banner i {
        font-size: 24px;
        color: #f59e0b;
    }
    
    .alert-banner-content {
        flex: 1;
    }
    
    .alert-banner-title {
        font-weight: 700;
        color: #92400e;
    }
    
    .alert-banner-message {
        font-size: 12px;
        color: #b45309;
        margin-top: 4px;
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
        border-radius: 24px;
        padding: 28px;
        width: 500px;
        max-width: 90%;
        max-height: 80vh;
        overflow-y: auto;
    }
    
    .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        padding-bottom: 12px;
        border-bottom: 1px solid var(--border);
    }
    
    .close-modal {
        cursor: pointer;
        font-size: 24px;
        color: var(--gray);
    }
    
    .empty-state {
        text-align: center;
        padding: 60px;
        background: white;
        border-radius: 24px;
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
    
    /* Refresh Button */
    .refresh-btn {
        background: white;
        border: 1px solid var(--border);
        padding: 8px 16px;
        border-radius: 40px;
        cursor: pointer;
        font-size: 13px;
        transition: all 0.3s;
    }
    
    .refresh-btn:hover {
        background: var(--primary);
        color: white;
        border-color: var(--primary);
    }
    
    @media (max-width: 1024px) {
        .stats-grid {
            grid-template-columns: repeat(3, 1fr);
        }
        .info-grid {
            grid-template-columns: 1fr;
            gap: 16px;
        }
    }
    
    @media (max-width: 768px) {
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }
        .dates-section {
            flex-direction: column;
        }
        .date-item {
            width: 100%;
        }
        .booking-header {
            flex-direction: column;
            align-items: flex-start;
        }
        .action-buttons {
            flex-direction: column;
        }
        .btn {
            justify-content: center;
        }
    }
</style>

<div class="renters-container">
    <div class="page-header">
        <div>
            <h1><i class="fas fa-users"></i> My Renters</h1>
            <p>Manage tenants who have booked your properties</p>
        </div>
        <div style="position: absolute; right: 32px; top: 32px;">
            <button class="refresh-btn" onclick="location.reload()">
                <i class="fas fa-sync-alt"></i> Refresh
            </button>
        </div>
    </div>
    
    <!-- Alert for New Bookings -->
    <?php if ($stats['pending'] > 0 || $stats['paid'] > 0): ?>
    <div class="alert-banner">
        <i class="fas fa-bell"></i>
        <div class="alert-banner-content">
            <div class="alert-banner-title">
                <?php if ($stats['pending'] > 0 && $stats['paid'] > 0): ?>
                    📢 You have <?php echo $stats['pending']; ?> pending booking(s) and <?php echo $stats['paid']; ?> new payment(s)!
                <?php elseif ($stats['pending'] > 0): ?>
                    📢 You have <?php echo $stats['pending']; ?> new pending booking request(s)!
                <?php elseif ($stats['paid'] > 0): ?>
                    💰 You have <?php echo $stats['paid']; ?> new deposit payment(s) received!
                <?php endif; ?>
            </div>
            <div class="alert-banner-message">
                Click on any booking to view full details and take action.
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Statistics -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?php echo $stats['total']; ?></div>
            <div class="stat-label">Total Bookings</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $stats['pending']; ?></div>
            <div class="stat-label">Pending</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $stats['confirmed']; ?></div>
            <div class="stat-label">Confirmed</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo formatMoney($stats['total_deposits']); ?></div>
            <div class="stat-label">Total Deposits</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo formatMoney($stats['total_earnings']); ?></div>
            <div class="stat-label">Total Earnings</div>
        </div>
    </div>
    
    <?php if (!empty($bookings_list)): ?>
        <?php foreach($bookings_list as $booking): ?>
            <div class="booking-card">
                <!-- Header with Status -->
                <div class="booking-header">
                    <div>
                        <?php
                        $status_color = '';
                        if ($booking['status'] == 'pending') $status_color = 'status-pending';
                        elseif ($booking['status'] == 'confirmed') $status_color = 'status-confirmed';
                        elseif ($booking['status'] == 'completed') $status_color = 'status-completed';
                        else $status_color = 'status-cancelled';
                        ?>
                        <span class="status-badge <?php echo $status_color; ?>">
                            <i class="fas <?php 
                                echo $booking['status'] == 'pending' ? 'fa-clock' : 
                                    ($booking['status'] == 'confirmed' ? 'fa-check-circle' : 
                                    ($booking['status'] == 'completed' ? 'fa-check-double' : 'fa-times-circle')); 
                            ?>"></i>
                            <?php echo ucfirst($booking['status']); ?>
                        </span>
                        <span class="payment-badge payment-<?php echo $booking['paid_amount'] > 0 ? 'paid' : 'pending'; ?>" style="margin-left: 8px;">
                            <i class="fas fa-credit-card"></i>
                            <?php echo $booking['paid_amount'] > 0 ? '💰 Deposit Paid' : '⏳ Awaiting Payment'; ?>
                        </span>
                        <?php if ($booking['paid_amount'] > 0): ?>
                            <span class="payment-badge" style="background: #10b981; color: white; margin-left: 8px;">
                                <i class="fas fa-check-circle"></i> Payment Confirmed
                            </span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <i class="fas fa-calendar"></i> 
                        Booked: <?php echo date('M d, Y', strtotime($booking['created_at'])); ?>
                    </div>
                </div>
                
                <div class="booking-body">
                    <!-- Property Info -->
                    <div class="property-section">
                        <div class="property-title">🏠 <?php echo htmlspecialchars($booking['property_title']); ?></div>
                        <div class="property-location">
                            <i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($booking['property_location'] ?: 'Location not specified'); ?>
                        </div>
                    </div>
                    
                    <!-- Renter Information Grid -->
                    <div class="info-grid">
                        <!-- Tenant Info -->
                        <div class="info-card">
                            <div class="info-card-title">
                                <i class="fas fa-user"></i> Renter Information
                            </div>
                            <div class="info-row">
                                <span class="info-label">Full Name</span>
                                <div class="info-value"><?php echo htmlspecialchars($booking['tenant_name']); ?></div>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Email Address</span>
                                <div class="info-value"><?php echo htmlspecialchars($booking['tenant_email']); ?></div>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Phone Number</span>
                                <div class="info-value"><?php echo htmlspecialchars($booking['tenant_phone'] ?: 'Not provided'); ?></div>
                            </div>
                            <?php if ($booking['tenant_address'] || $booking['tenant_city']): ?>
                            <div class="info-row">
                                <span class="info-label">Address</span>
                                <div class="info-value">
                                    <?php echo htmlspecialchars($booking['tenant_address'] ?: ''); ?>
                                    <?php echo htmlspecialchars($booking['tenant_city'] ?: ''); ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Payment Info -->
                        <div class="info-card">
                            <div class="info-card-title">
                                <i class="fas fa-money-bill-wave"></i> Payment Details
                            </div>
                            <div class="info-row">
                                <span class="info-label">Total Amount</span>
                                <div class="info-value amount-large"><?php echo formatMoney($booking['total_amount']); ?></div>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Deposit Paid (in Escrow)</span>
                                <div class="info-value" style="color: var(--success); font-size: 18px; font-weight: 700;">
                                    <?php echo formatMoney($booking['deposit_paid']); ?>
                                </div>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Remaining to Collect</span>
                                <div class="info-value"><?php echo formatMoney($booking['total_amount'] - $booking['deposit_paid']); ?></div>
                            </div>
                            <?php if ($booking['payment_date']): ?>
                            <div class="info-row">
                                <span class="info-label">Payment Date</span>
                                <div class="info-value"><?php echo date('M d, Y H:i', strtotime($booking['payment_date'])); ?></div>
                            </div>
                            <?php endif; ?>
                            <?php if ($booking['payment_code']): ?>
                            <div class="info-row">
                                <span class="info-label">Transaction Code</span>
                                <div class="info-value"><code><?php echo $booking['payment_code']; ?></code></div>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Booking Info -->
                        <div class="info-card">
                            <div class="info-card-title">
                                <i class="fas fa-calendar-alt"></i> Booking Details
                            </div>
                            <div class="info-row">
                                <span class="info-label">Booking Reference</span>
                                <div class="info-value">#<?php echo $booking['id']; ?></div>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Transaction ID</span>
                                <div class="info-value">#<?php echo $booking['transaction_id']; ?></div>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Nights</span>
                                <div class="info-value"><?php echo $booking['total_nights']; ?> nights</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Dates Section -->
                    <div class="dates-section">
                        <div class="date-item">
                            <div class="date-label"><i class="fas fa-calendar-check"></i> Check-in</div>
                            <div class="date-value"><?php echo date('F d, Y', strtotime($booking['check_in_date'])); ?></div>
                            <div style="font-size: 12px; color: var(--gray);">
                                <?php echo date('l', strtotime($booking['check_in_date'])); ?> at 3:00 PM
                            </div>
                        </div>
                        <div class="date-item">
                            <div class="date-label"><i class="fas fa-calendar-times"></i> Check-out</div>
                            <div class="date-value"><?php echo date('F d, Y', strtotime($booking['check_out_date'])); ?></div>
                            <div style="font-size: 12px; color: var(--gray);">
                                <?php echo date('l', strtotime($booking['check_out_date'])); ?> at 11:00 AM
                            </div>
                        </div>
                        <div class="nights-count">
                            <i class="fas fa-moon"></i> <?php echo $booking['total_nights']; ?> nights
                        </div>
                    </div>
                    
                    <!-- Special Requests -->
                    <?php if (!empty($booking['special_requests'])): ?>
                    <div class="special-requests">
                        <strong><i class="fas fa-comment-dots"></i> Special Request from Renter:</strong>
                        <p style="margin-top: 8px; font-size: 13px;"><?php echo nl2br(htmlspecialchars($booking['special_requests'])); ?></p>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Action Buttons -->
                    <div class="action-buttons">
                        <button onclick="viewRenterDetails(<?php echo htmlspecialchars(json_encode($booking)); ?>)" class="btn btn-primary">
                            <i class="fas fa-user-circle"></i> View Full Details
                        </button>
                        <?php if ($booking['status'] == 'confirmed' && $booking['paid_amount'] > 0): ?>
                            <button onclick="markAsCompleted(<?php echo $booking['id']; ?>)" class="btn btn-success">
                                <i class="fas fa-check-double"></i> Mark as Completed
                            </button>
                        <?php endif; ?>
                        <a href="/broker_system/user/chat.php?user=<?php echo $booking['tenant_id']; ?>" class="btn btn-outline">
                            <i class="fas fa-comment"></i> Message Renter
                        </a>
                        <a href="/broker_system/user/transaction.php?id=<?php echo $booking['transaction_id']; ?>" class="btn btn-outline">
                            <i class="fas fa-receipt"></i> View Transaction
                        </a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="empty-state">
            <div class="empty-state-icon">
                <i class="fas fa-users"></i>
            </div>
            <h3>No Renters Yet</h3>
            <p>When someone books your property, they'll appear here with all their details.</p>
            <a href="listings.php" class="btn btn-primary" style="margin-top: 16px; display: inline-block;">
                <i class="fas fa-plus-circle"></i> List Your Property
            </a>
        </div>
    <?php endif; ?>
</div>

<!-- Renter Details Modal -->
<div id="renterModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-user-circle"></i> Complete Renter Details</h3>
            <span class="close-modal" onclick="closeRenterModal()">&times;</span>
        </div>
        <div id="renterDetailsContent"></div>
    </div>
</div>

<script>
function viewRenterDetails(booking) {
    const modalContent = document.getElementById('renterDetailsContent');
    modalContent.innerHTML = `
        <div style="margin-bottom: 20px;">
            <h4 style="color: #667eea; margin-bottom: 10px;">📋 Personal Information</h4>
            <p><strong>Full Name:</strong> ${escapeHtml(booking.tenant_name)}</p>
            <p><strong>Email:</strong> ${escapeHtml(booking.tenant_email)}</p>
            <p><strong>Phone:</strong> ${escapeHtml(booking.tenant_phone || 'Not provided')}</p>
            <p><strong>Address:</strong> ${escapeHtml(booking.tenant_address || 'Not provided')}</p>
            <p><strong>City:</strong> ${escapeHtml(booking.tenant_city || 'Not provided')}</p>
        </div>
        <div style="margin-bottom: 20px;">
            <h4 style="color: #667eea; margin-bottom: 10px;">🏠 Booking Information</h4>
            <p><strong>Property:</strong> ${escapeHtml(booking.property_title)}</p>
            <p><strong>Check-in:</strong> ${new Date(booking.check_in_date).toLocaleDateString()}</p>
            <p><strong>Check-out:</strong> ${new Date(booking.check_out_date).toLocaleDateString()}</p>
            <p><strong>Nights:</strong> ${booking.total_nights}</p>
        </div>
        <div style="margin-bottom: 20px;">
            <h4 style="color: #667eea; margin-bottom: 10px;">💰 Payment Information</h4>
            <p><strong>Total Amount:</strong> ${formatMoney(booking.total_amount)}</p>
            <p><strong>Deposit Paid (in Escrow):</strong> <span style="color: #10b981; font-weight: bold;">${formatMoney(booking.deposit_paid)}</span></p>
            <p><strong>Platform Commission:</strong> ${formatMoney(booking.commission_amount)}</p>
            <p><strong>You Will Receive:</strong> ${formatMoney(booking.total_amount - booking.commission_amount)}</p>
            ${booking.payment_date ? `<p><strong>Paid on:</strong> ${new Date(booking.payment_date).toLocaleString()}</p>` : ''}
        </div>
        ${booking.special_requests ? `
        <div style="margin-top: 20px; padding: 12px; background: #fef3c7; border-radius: 12px;">
            <strong>💬 Special Request:</strong>
            <p style="margin-top: 8px;">${escapeHtml(booking.special_requests)}</p>
        </div>
        ` : ''}
        <div style="margin-top: 20px; padding: 12px; background: #dbeafe; border-radius: 12px;">
            <strong>ℹ️ Important Information:</strong>
            <p style="margin-top: 8px; font-size: 12px;">
                • The deposit is held in escrow and will be released after check-out.<br>
                • You will receive the remaining payment directly from the tenant.<br>
                • Contact the tenant for any special arrangements.
            </p>
        </div>
    `;
    document.getElementById('renterModal').style.display = 'flex';
}

function closeRenterModal() {
    document.getElementById('renterModal').style.display = 'none';
}

function markAsCompleted(bookingId) {
    if (confirm('Mark this booking as completed? This will release the escrow payment to you.')) {
        fetch('/broker_system/api/complete_booking.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ booking_id: bookingId })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('✓ Booking marked as completed! Payment has been released to your wallet.');
                location.reload();
            } else {
                alert('Error: ' + data.error);
            }
        })
        .catch(error => {
            alert('Error: ' + error);
        });
    }
}

function formatMoney(amount) {
    return new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(amount) + ' ETB';
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

window.onclick = function(event) {
    const modal = document.getElementById('renterModal');
    if (event.target === modal) {
        modal.style.display = 'none';
    }
}
</script>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>