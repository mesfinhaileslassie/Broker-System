<?php
// user/owner_bookings.php - Complete Owner Dashboard with All Renter Info

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/escrow_functions.php';

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
        t.escrow_status,
        t.delivery_status,
        t.escrow_release_date,
        t.auto_release_days,
        t.handover_confirmed,
        t.handover_confirmed_at,
        t.buyer_confirmed_at,
        t.payment_released_at,
        (SELECT SUM(amount) FROM payments WHERE transaction_id = t.id AND status = 'confirmed' AND type = 'deposit_buyer') as buyer_deposit_paid,
        (SELECT SUM(amount) FROM payments WHERE transaction_id = t.id AND status = 'confirmed' AND type = 'commission') as commission_paid,
        (SELECT SUM(amount) FROM payments WHERE transaction_id = t.id AND status = 'confirmed') as total_paid,
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
    'deposit_received' => 0,
    'total_earnings' => 0,
    'total_deposits' => 0,
    'total_commission' => 0,
    'escrow_held' => 0
];

$bookings_list = [];
if ($bookings && $bookings->num_rows > 0) {
    while ($row = $bookings->fetch_assoc()) {
        $bookings_list[] = $row;
        $stats['total']++;
        
        if ($row['status'] == 'pending') $stats['pending']++;
        if ($row['status'] == 'confirmed') $stats['confirmed']++;
        if ($row['status'] == 'completed') $stats['completed']++;
        
        $has_payment = ($row['buyer_deposit_paid'] > 0 || $row['commission_paid'] > 0);
        if ($has_payment) $stats['paid']++;
        if ($row['deposit_paid'] > 0) $stats['deposit_received']++;
        
        $stats['total_deposits'] += $row['deposit_paid'];
        $stats['total_commission'] += $row['commission_amount'];
        $stats['escrow_held'] += $row['escrow_held'];
        
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
    .payment-partial { background: #fef3c7; color: #92400e; }
    
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
    
    /* Payment Status Card */
    .payment-status-card {
        background: linear-gradient(135deg, #d1fae5, #a7f3d0);
        border-radius: 16px;
        padding: 16px;
        margin-bottom: 20px;
        border: 1px solid #10b981;
    }
    
    .payment-status-card h4 {
        color: #065f46;
        margin-bottom: 12px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    /* Renter Highlight Card */
    .renter-highlight-card {
        background: linear-gradient(135deg, #e0f2fe, #bae6fd);
        border-radius: 16px;
        padding: 20px;
        margin-bottom: 20px;
        border: 2px solid #38bdf8;
    }
    
    .renter-highlight-card h4 {
        color: #0369a1;
        margin-bottom: 16px;
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 16px;
    }
    
    .renter-detail-row {
        display: flex;
        margin-bottom: 12px;
        padding: 8px;
        background: white;
        border-radius: 12px;
    }
    
    .renter-label {
        width: 100px;
        font-size: 12px;
        font-weight: 600;
        color: #64748b;
    }
    
    .renter-value {
        flex: 1;
        font-size: 14px;
        font-weight: 600;
        color: #1e293b;
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
    
    /* Escrow Info */
    .escrow-info {
        background: #dbeafe;
        border-radius: 12px;
        padding: 12px;
        margin: 12px 0;
        font-size: 12px;
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
    
    .btn-warning {
        background: var(--warning);
        color: white;
    }
    
    .btn-warning:hover {
        background: #d97706;
    }
    
    .btn-danger {
        background: var(--danger);
        color: white;
    }
    
    .btn-danger:hover {
        background: #dc2626;
    }
    
    /* Alert Banner */
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
        width: 550px;
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
        .renter-detail-row {
            flex-direction: column;
        }
        .renter-label {
            width: 100%;
            margin-bottom: 5px;
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
    
    <!-- Alert for New Bookings or Payments -->
    <?php if ($stats['pending'] > 0 || $stats['paid'] > 0): ?>
    <div class="alert-banner">
        <i class="fas fa-bell"></i>
        <div class="alert-banner-content">
            <div class="alert-banner-title">
                <?php if ($stats['pending'] > 0 && $stats['paid'] > 0): ?>
                    📢 You have <?php echo $stats['pending']; ?> pending booking(s) and <?php echo $stats['paid']; ?> new payment(s) received!
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
            <div class="stat-value"><?php echo $stats['deposit_received']; ?></div>
            <div class="stat-label">Deposits Received</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo formatMoney($stats['escrow_held']); ?></div>
            <div class="stat-label">Escrow Held</div>
        </div>
    </div>
    
    <?php if (!empty($bookings_list)): ?>
        <?php foreach($bookings_list as $booking): 
            $has_payment = ($booking['buyer_deposit_paid'] > 0 || $booking['commission_paid'] > 0);
            $payment_status = 'pending';
            if ($booking['buyer_deposit_paid'] > 0 && $booking['commission_paid'] > 0) {
                $payment_status = 'paid';
            } elseif ($booking['buyer_deposit_paid'] > 0) {
                $payment_status = 'partial';
            }
        ?>
            <div class="booking-card">
                <!-- Header with Status -->
                <div class="booking-header">
                    <div>
                        <span class="status-badge <?php 
                            echo $booking['status'] == 'pending' ? 'status-pending' : 
                                ($booking['status'] == 'confirmed' ? 'status-confirmed' : 
                                ($booking['status'] == 'completed' ? 'status-completed' : 'status-cancelled')); 
                        ?>">
                            <i class="fas <?php 
                                echo $booking['status'] == 'pending' ? 'fa-clock' : 
                                    ($booking['status'] == 'confirmed' ? 'fa-check-circle' : 
                                    ($booking['status'] == 'completed' ? 'fa-check-double' : 'fa-times-circle')); 
                            ?>"></i>
                            <?php echo ucfirst($booking['status']); ?>
                        </span>
                        
                        <span class="payment-badge payment-<?php echo $payment_status; ?>" style="margin-left: 8px;">
                            <i class="fas fa-credit-card"></i>
                            <?php if ($payment_status == 'paid'): ?>
                                💰 Deposit & Commission Paid
                            <?php elseif ($payment_status == 'partial'): ?>
                                ⏳ Deposit Paid (Commission Pending)
                            <?php else: ?>
                                ⏳ Awaiting Payment
                            <?php endif; ?>
                        </span>
                        
                        <?php if ($booking['escrow_status'] == 'active'): ?>
                            <span class="payment-badge" style="background: #dbeafe; color: #1e40af; margin-left: 8px;">
                                <i class="fas fa-shield-alt"></i> Escrow Active
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
                    
                    <!-- RENTER / GUEST HIGHLIGHT CARD - WHO IS BOOKING -->
                    <div class="renter-highlight-card">
                        <h4><i class="fas fa-user-circle"></i> 🧑 GUEST / RENTER INFORMATION</h4>
                        <div class="renter-detail-row">
                            <div class="renter-label"><i class="fas fa-user"></i> Full Name:</div>
                            <div class="renter-value"><?php echo htmlspecialchars($booking['tenant_name']); ?></div>
                        </div>
                        <div class="renter-detail-row">
                            <div class="renter-label"><i class="fas fa-envelope"></i> Email:</div>
                            <div class="renter-value"><?php echo htmlspecialchars($booking['tenant_email']); ?></div>
                        </div>
                        <div class="renter-detail-row">
                            <div class="renter-label"><i class="fas fa-phone"></i> Phone:</div>
                            <div class="renter-value"><?php echo htmlspecialchars($booking['tenant_phone'] ?: 'Not provided'); ?></div>
                        </div>
                        <?php if ($booking['tenant_address'] || $booking['tenant_city']): ?>
                        <div class="renter-detail-row">
                            <div class="renter-label"><i class="fas fa-map-marker-alt"></i> Address:</div>
                            <div class="renter-value"><?php echo htmlspecialchars($booking['tenant_address'] ?: ''); ?> <?php echo htmlspecialchars($booking['tenant_city'] ?: ''); ?></div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Quick Contact Buttons -->
                        <div style="margin-top: 16px; display: flex; gap: 10px; flex-wrap: wrap;">
                            <a href="mailto:<?php echo $booking['tenant_email']; ?>" class="btn btn-outline" style="font-size: 12px; padding: 6px 12px;">
                                <i class="fas fa-envelope"></i> Send Email
                            </a>
                            <?php if ($booking['tenant_phone']): ?>
                            <a href="tel:<?php echo $booking['tenant_phone']; ?>" class="btn btn-outline" style="font-size: 12px; padding: 6px 12px;">
                                <i class="fas fa-phone"></i> Call
                            </a>
                            <?php endif; ?>
                            <a href="/broker_system/user/chat.php?user=<?php echo $booking['tenant_id']; ?>" class="btn btn-outline" style="font-size: 12px; padding: 6px 12px;">
                                <i class="fas fa-comment"></i> Chat
                            </a>
                        </div>
                    </div>
                    
                    <!-- PAYMENT INFORMATION -->
                    <?php if ($has_payment): ?>
                    <div class="payment-status-card">
                        <h4><i class="fas fa-check-circle"></i> ✅ Payment Received!</h4>
                        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px;">
                            <div>
                                <div class="info-label">Deposit Paid</div>
                                <div class="info-value" style="color: #059669; font-size: 18px;">
                                    <?php echo formatMoney($booking['deposit_paid']); ?>
                                </div>
                            </div>
                            <div>
                                <div class="info-label">Commission Paid</div>
                                <div class="info-value" style="color: #059669; font-size: 18px;">
                                    <?php echo formatMoney($booking['commission_amount']); ?>
                                </div>
                            </div>
                            <div>
                                <div class="info-label">Total in Escrow</div>
                                <div class="info-value"><?php echo formatMoney($booking['escrow_held']); ?></div>
                            </div>
                            <?php if ($booking['payment_code']): ?>
                            <div>
                                <div class="info-label">Payment Code</div>
                                <div class="info-value"><code><?php echo $booking['payment_code']; ?></code></div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php if ($booking['payment_date']): ?>
                        <div style="margin-top: 12px; font-size: 11px; color: #065f46;">
                            <i class="fas fa-clock"></i> Payment confirmed on: <?php echo date('M d, Y H:i', strtotime($booking['payment_date'])); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <div class="payment-status-card" style="background: #fef3c7; border-color: #f59e0b;">
                        <h4 style="color: #92400e;"><i class="fas fa-clock"></i> ⏳ Awaiting Payment</h4>
                        <p style="font-size: 12px; color: #92400e;">The guest has not completed payment yet. They will pay deposit + commission to secure the booking.</p>
                        <div class="info-row" style="margin-top: 8px;">
                            <span class="info-label">Deposit Required:</span>
                            <span class="info-value"><?php echo formatMoney($booking['deposit_amount']); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Commission:</span>
                            <span class="info-value"><?php echo formatMoney($booking['commission_amount']); ?></span>
                        </div>
                    </div>
                    <?php endif; ?>
                    
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
                    
                    <!-- Escrow Information -->
                    <?php if ($booking['escrow_status'] == 'active'): ?>
                    <div class="escrow-info">
                        <i class="fas fa-shield-alt"></i> <strong>🔒 Escrow Protection Active</strong><br>
                        <small>Funds are held securely in escrow. They will be released after check-out or when you confirm handover.</small>
                        <?php if ($booking['escrow_release_date']): ?>
                        <div style="margin-top: 8px;">
                            <i class="fas fa-clock"></i> Auto-release scheduled: <?php echo date('M d, Y', strtotime($booking['escrow_release_date'])); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Special Requests -->
                    <?php if (!empty($booking['special_requests'])): ?>
                    <div class="special-requests">
                        <strong><i class="fas fa-comment-dots"></i> 💬 Special Request from Guest:</strong>
                        <p style="margin-top: 8px; font-size: 13px;"><?php echo nl2br(htmlspecialchars($booking['special_requests'])); ?></p>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Action Buttons -->
                    <div class="action-buttons">
                        <button onclick="viewRenterDetails(<?php echo htmlspecialchars(json_encode($booking)); ?>)" class="btn btn-primary">
                            <i class="fas fa-user-circle"></i> View Full Details
                        </button>
                        
                        <?php if ($booking['status'] == 'pending'): ?>
                            <button onclick="processBooking(<?php echo $booking['id']; ?>, 'approve')" class="btn btn-success">
                                <i class="fas fa-check-circle"></i> ✅ Approve Booking
                            </button>
                            <button onclick="processBooking(<?php echo $booking['id']; ?>, 'reject')" class="btn btn-danger">
                                <i class="fas fa-times-circle"></i> ❌ Reject Booking
                            </button>
                        <?php endif; ?>
                        
                        <?php if ($booking['status'] == 'confirmed' && $has_payment && $booking['handover_confirmed'] != 1): ?>
                            <button onclick="confirmHandover(<?php echo $booking['id']; ?>)" class="btn btn-success">
                                <i class="fas fa-key"></i> 🏠 Confirm Handover
                            </button>
                        <?php endif; ?>
                        
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

<!-- Handover Modal -->
<div id="handoverModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-key"></i> Confirm Handover</h3>
            <span class="close-modal" onclick="closeHandoverModal()">&times;</span>
        </div>
        <form method="POST" action="confirm_handover.php">
            <input type="hidden" name="booking_id" id="handoverBookingId">
            <div class="form-group">
                <label>Handover Notes (Optional)</label>
                <textarea name="handover_notes" rows="3" placeholder="Add any notes about the handover..."></textarea>
            </div>
            <button type="submit" class="btn btn-success" style="width: 100%;">
                <i class="fas fa-check-circle"></i> Confirm Handover
            </button>
        </form>
    </div>
</div>

<script>
let currentBookingId = null;

function viewRenterDetails(booking) {
    const modalContent = document.getElementById('renterDetailsContent');
    const hasPayment = booking.buyer_deposit_paid > 0 || booking.commission_paid > 0;
    
    modalContent.innerHTML = `
        <div style="margin-bottom: 20px;">
            <h4 style="color: #667eea; margin-bottom: 10px;"><i class="fas fa-user"></i> Personal Information</h4>
            <p><strong>Full Name:</strong> ${escapeHtml(booking.tenant_name)}</p>
            <p><strong>Email:</strong> ${escapeHtml(booking.tenant_email)}</p>
            <p><strong>Phone:</strong> ${escapeHtml(booking.tenant_phone || 'Not provided')}</p>
            <p><strong>Address:</strong> ${escapeHtml(booking.tenant_address || 'Not provided')}</p>
            <p><strong>City:</strong> ${escapeHtml(booking.tenant_city || 'Not provided')}</p>
        </div>
        
        <div style="margin-bottom: 20px;">
            <h4 style="color: #667eea; margin-bottom: 10px;"><i class="fas fa-home"></i> Booking Information</h4>
            <p><strong>Property:</strong> ${escapeHtml(booking.property_title)}</p>
            <p><strong>Check-in:</strong> ${new Date(booking.check_in_date).toLocaleDateString()}</p>
            <p><strong>Check-out:</strong> ${new Date(booking.check_out_date).toLocaleDateString()}</p>
            <p><strong>Nights:</strong> ${booking.total_nights}</p>
        </div>
        
        <div style="margin-bottom: 20px;">
            <h4 style="color: #667eea; margin-bottom: 10px;"><i class="fas fa-money-bill-wave"></i> Payment Information</h4>
            ${hasPayment ? `
                <div style="background: #d1fae5; padding: 12px; border-radius: 12px; margin-bottom: 12px;">
                    <p><strong>✅ Payment Status:</strong> Payment Received!</p>
                    <p><strong>Deposit Paid:</strong> ${formatMoney(booking.deposit_paid)}</p>
                    <p><strong>Commission Paid:</strong> ${formatMoney(booking.commission_amount)}</p>
                    <p><strong>Total in Escrow:</strong> ${formatMoney(booking.escrow_held)}</p>
                </div>
            ` : `
                <div style="background: #fef3c7; padding: 12px; border-radius: 12px; margin-bottom: 12px;">
                    <p><strong>⚠️ Payment Status:</strong> Awaiting Payment</p>
                    <p><strong>Deposit Required:</strong> ${formatMoney(booking.deposit_amount)}</p>
                    <p><strong>Commission:</strong> ${formatMoney(booking.commission_amount)}</p>
                </div>
            `}
            <p><strong>Total Amount:</strong> ${formatMoney(booking.total_amount)}</p>
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
                • The deposit is held in escrow and will be released after check-out or when you confirm handover.<br>
                • You will receive the remaining payment directly from the guest.<br>
                • Contact the guest for any special arrangements.
            </p>
        </div>
        
        <div style="margin-top: 20px; display: flex; gap: 10px; flex-wrap: wrap;">
            <a href="mailto:${escapeHtml(booking.tenant_email)}" class="btn btn-primary" style="flex: 1; justify-content: center;">
                <i class="fas fa-envelope"></i> Send Email
            </a>
            <a href="tel:${escapeHtml(booking.tenant_phone)}" class="btn btn-outline" style="flex: 1; justify-content: center;">
                <i class="fas fa-phone"></i> Call
            </a>
            <button onclick="closeRenterModal()" class="btn btn-outline">Close</button>
        </div>
    `;
    document.getElementById('renterModal').style.display = 'flex';
}

function processBooking(bookingId, action) {
    if (!confirm(`Are you sure you want to ${action} this booking?`)) return;
    
    fetch('/broker_system/user/transaction_actions.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: action === 'approve' ? 'approve_booking' : 'reject_booking',
            transaction_id: bookingId
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            location.reload();
        } else {
            alert('Error: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(error => {
        alert('Error: ' + error);
    });
}

function confirmHandover(bookingId) {
    if (!confirm('Confirm that you have handed over the property to the guest? The deposit will be released after guest confirmation.')) return;
    
    fetch('/broker_system/user/transaction_actions.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'confirm_handover',
            transaction_id: bookingId,
            notes: 'Property handed over to guest'
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Handover confirmed! Waiting for guest confirmation to release deposit.');
            location.reload();
        } else {
            alert('Error: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(error => {
        alert('Error: ' + error);
    });
}

function closeRenterModal() {
    document.getElementById('renterModal').style.display = 'none';
}

function closeHandoverModal() {
    document.getElementById('handoverModal').style.display = 'none';
}

function formatMoney(amount) {
    if (!amount) return '0.00 ETB';
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
    const handoverModal = document.getElementById('handoverModal');
    if (event.target === modal) {
        modal.style.display = 'none';
    }
    if (event.target === handoverModal) {
        handoverModal.style.display = 'none';
    }
}

// Auto-refresh every 30 seconds
setInterval(function() {
    location.reload();
}, 30000);
</script>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>