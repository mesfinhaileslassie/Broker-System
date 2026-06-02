<?php
// api/confirm_payment.php - Confirm payment by code (all payment types)
// FIXED: Now marks products as reserved/sold when deposit is paid

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../config/database.php';
require_once '../includes/payment_confirm.php';
require_once '../includes/functions.php';

date_default_timezone_set('Africa/Addis_Ababa');

// Debug logging
$debug_log = __DIR__ . '/confirm_payment_debug.log';
function debug_log_confirm($message, $data = null) {
    global $debug_log;
    $log_entry = date('Y-m-d H:i:s') . " - " . $message;
    if ($data !== null) {
        $log_entry .= " - " . print_r($data, true);
    }
    file_put_contents($debug_log, $log_entry . PHP_EOL, FILE_APPEND);
}

debug_log_confirm("========== CONFIRM PAYMENT CALLED ==========");

$conn = getDbConnection();
$conn->query("SET time_zone = '+03:00'");

$input = json_decode(file_get_contents('php://input'), true) ?: [];

$code = trim($input['payment_code'] ?? $input['code'] ?? '');
$pin = trim($input['pin'] ?? '');
$bypass_pin = isset($input['bypass_pin']) && $input['bypass_pin'] === true;

debug_log_confirm("Payment code: $code");
debug_log_confirm("PIN provided: " . (empty($pin) ? 'NO' : 'YES'));

if ($code === '') {
    echo json_encode(['success' => false, 'error' => 'Payment code is required']);
    $conn->close();
    exit;
}

$user_id = null;
if (isset($_SESSION['user_logged_in']) && $_SESSION['user_logged_in']) {
    $user_id = (int) $_SESSION['user_id'];
    debug_log_confirm("User ID from session: $user_id");
    
    // Logged-in users must confirm with test PIN (Telebirr demo) unless bypassed
    if (!$bypass_pin && $pin !== '1234') {
        echo json_encode(['success' => false, 'error' => 'Incorrect PIN. Use 1234 for testing']);
        $conn->close();
        exit;
    }
}

// Call the payment confirmation function
$result = confirmPaymentByCode($conn, $code, ['user_id' => $user_id]);

// ============================================
// CRITICAL FIX: Mark product as reserved/sold after deposit payment
// ============================================
if ($result['success']) {
    debug_log_confirm("Payment confirmed successfully. Checking if product needs to be marked as reserved...");
    
    // Get payment and transaction details
    $payment_info = $conn->query("
        SELECT 
            p.*,
            t.id as transaction_id,
            t.listing_id,
            t.buyer_id,
            t.seller_id,
            t.type as transaction_type,
            l.type as listing_type,
            l.availability_status,
            l.sold_to_user_id,
            l.status as listing_status
        FROM payments p
        JOIN transactions t ON p.transaction_id = t.id
        JOIN listings l ON t.listing_id = l.id
        WHERE p.telebirr_code_5digit = '$code'
        AND p.status = 'confirmed'
        ORDER BY p.id DESC
        LIMIT 1
    ");
    
    if ($payment_info && $payment_info->num_rows > 0) {
        $payment = $payment_info->fetch_assoc();
        debug_log_confirm("Payment details found:", $payment);
        
        $payment_type = $payment['type'];
        $listing_id = $payment['listing_id'];
        $buyer_id = $payment['buyer_id'];
        $listing_type = $payment['listing_type'];
        $current_availability = $payment['availability_status'];
        
        debug_log_confirm("Listing ID: $listing_id, Type: $listing_type, Current availability: $current_availability");
        debug_log_confirm("Payment type: $payment_type, Buyer ID: $buyer_id");
        
        // ============================================
        // FOR PRODUCT LISTINGS: Mark as reserved when buyer pays deposit
        // ============================================
        if ($listing_type === 'product' && $payment_type === 'deposit_buyer') {
            debug_log_confirm("Processing PRODUCT deposit payment - marking as reserved");
            
            // Only mark as reserved if currently available
            if ($current_availability === 'available' || $current_availability === null) {
                $update_listing = $conn->prepare("
                    UPDATE listings 
                    SET availability_status = 'reserved',
                        sold_to_user_id = ?,
                        sold_at = NOW(),
                        updated_at = NOW()
                    WHERE id = ? 
                    AND (availability_status = 'available' OR availability_status IS NULL)
                ");
                $update_listing->bind_param("ii", $buyer_id, $listing_id);
                $update_listing->execute();
                
                if ($conn->affected_rows > 0) {
                    debug_log_confirm("✅ PRODUCT MARKED AS RESERVED! Listing ID: $listing_id, Buyer ID: $buyer_id");
                    
                    // Also update the transaction to track reservation
                    $conn->query("
                        UPDATE transactions 
                        SET reservation_status = 'active',
                            reserved_at = NOW(),
                            reserved_by = $buyer_id
                        WHERE id = {$payment['transaction_id']}
                    ");
                    
                    $result['listing_reserved'] = true;
                    $result['listing_id'] = $listing_id;
                    $result['message'] = ($result['message'] ?? 'Payment confirmed') . ' The item has been reserved for you.';
                } else {
                    debug_log_confirm("⚠️ Failed to mark product as reserved - no rows affected. Current availability: $current_availability");
                    $result['warning'] = 'Payment confirmed but the item may already be reserved by another buyer.';
                }
            } else {
                debug_log_confirm("⚠️ Product not marked as reserved - current availability is '$current_availability' (not 'available')");
                $result['warning'] = 'Payment confirmed but this item is no longer available for reservation.';
            }
        }
        
        // ============================================
        // FOR RENTAL LISTINGS: Ensure dates are blocked (handled by AvailabilityManager)
        // ============================================
        elseif ($listing_type === 'rental' && $payment_type === 'deposit_buyer') {
            debug_log_confirm("Processing RENTAL deposit payment - checking availability manager");
            
            // Update listing availability status to 'reserved' for rentals too
            if ($current_availability === 'available' || $current_availability === null) {
                $update_listing = $conn->prepare("
                    UPDATE listings 
                    SET availability_status = 'reserved',
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $update_listing->bind_param("i", $listing_id);
                $update_listing->execute();
                debug_log_confirm("✅ Rental listing marked as reserved: $listing_id");
            }
            
            $result['listing_reserved'] = true;
        }
        
        // ============================================
        // FOR JOB LISTINGS: Mark position as filled
        // ============================================
        elseif ($listing_type === 'job' && $payment_type === 'deposit_buyer') {
            debug_log_confirm("Processing JOB deposit payment - checking if position should be marked as filled");
            
            // Check if this job should be marked as filled (based on application acceptance)
            $job_check = $conn->query("
                SELECT ja.status 
                FROM job_applications ja
                WHERE ja.job_id = $listing_id AND ja.applicant_id = $buyer_id AND ja.status = 'hired'
            ");
            
            if ($job_check && $job_check->num_rows > 0) {
                $update_listing = $conn->prepare("
                    UPDATE listings 
                    SET availability_status = 'filled',
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $update_listing->bind_param("i", $listing_id);
                $update_listing->execute();
                debug_log_confirm("✅ Job marked as filled: $listing_id");
                $result['listing_filled'] = true;
            }
        }
    } else {
        debug_log_confirm("WARNING: Could not find payment details for code: $code");
    }
} else {
    debug_log_confirm("Payment confirmation FAILED: " . ($result['error'] ?? 'Unknown error'));
}

$conn->close();
echo json_encode($result);