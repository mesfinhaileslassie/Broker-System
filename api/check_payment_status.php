<?php
// api/check_payment_status.php - SINGLE SOURCE OF TRUTH

session_start();
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

require_once '../config/database.php';

date_default_timezone_set('Africa/Addis_Ababa');

if (!isset($_SESSION['user_logged_in']) || !$_SESSION['user_logged_in']) {
    echo json_encode(['status' => 'error', 'message' => 'unauthorized']);
    exit;
}

$code = isset($_GET['code']) ? preg_replace('/[^0-9]/', '', $_GET['code']) : '';
$user_id = $_SESSION['user_id'];

if (empty($code) || strlen($code) != 5) {
    echo json_encode(['status' => 'error', 'message' => 'invalid_code']);
    exit;
}

$conn = getDbConnection();
$conn->query("SET time_zone = '+03:00'");

// SINGLE QUERY - Gets ALL status information including payment type
<?php
// api/check_payment_status.php - Add this at the top of your existing file
// Make sure to add 'commission' to the IN clause

// In the SELECT query, add pc.type:
$result = $conn->query("
    SELECT 
        pc.id,
        pc.code,
        pc.status as code_status,
        pc.user_id,
        pc.transaction_id,
        pc.amount,
        pc.type as payment_code_type,  -- ADD THIS LINE
        UNIX_TIMESTAMP(pc.expires_at) * 1000 as expires_at_ms,
        TIMESTAMPDIFF(SECOND, NOW(), pc.expires_at) as seconds_remaining,
        CASE 
            WHEN pc.status = 'used' THEN 'used'
            WHEN pc.expires_at <= NOW() THEN 'expired'
            WHEN pc.expires_at > NOW() THEN 'active'
            ELSE 'unknown'
        END as calculated_status,
        EXISTS(
            SELECT 1 FROM payments p 
            WHERE p.telebirr_code_5digit = pc.code 
            AND p.user_id = pc.user_id 
            AND p.type IN ('deposit_seller', 'deposit_buyer', 'service_fee', 'remaining_balance', 'commission')
            AND p.status = 'confirmed'
        ) as is_paid,
        EXISTS(
            SELECT 1 FROM transactions t
            JOIN listings l ON t.listing_id = l.id
            WHERE t.id = pc.transaction_id 
            AND l.status = 'active'
        ) as listing_active
    FROM payment_codes pc
    WHERE pc.code = '$code' 
    AND pc.user_id = $user_id
    LIMIT 1
");

if ($result->num_rows === 0) {
    echo json_encode([
        'status' => 'not_found',
        'valid' => false,
        'message' => 'Payment code not found'
    ]);
    $conn->close();
    exit;
}

$data = $result->fetch_assoc();

// Get listing availability status
$listing_status = $conn->query("
    SELECT availability_status, sold_to_user_id 
    FROM listings l
    JOIN transactions t ON t.listing_id = l.id
    WHERE t.id = {$data['transaction_id']}
")->fetch_assoc();

// Build response based on backend authority ONLY
$response = [
    'code' => $data['code'],
    'status' => $data['calculated_status'],
    'valid' => ($data['calculated_status'] === 'active'),
    'is_paid' => (bool)$data['is_paid'],
    'listing_active' => (bool)$data['listing_active'],
    'payment_code_type' => $data['payment_code_type'],
    'seconds_remaining' => max(0, intval($data['seconds_remaining'])),
    'expires_at' => intval($data['expires_at_ms']),
    'server_time' => time() * 1000,
    'listing_availability' => $listing_status['availability_status'] ?? 'available',
    'is_reserved' => ($listing_status['availability_status'] ?? '') === 'reserved'
];

// ============================================
// CRITICAL: Handle commission payment for jobs
// ============================================
if ($data['is_paid'] && $data['payment_code_type'] == 'commission') {
    // Get transaction and listing details
    $txn_info = $conn->query("
        SELECT t.listing_id, t.seller_id, l.title, l.type
        FROM transactions t
        JOIN listings l ON t.listing_id = l.id
        WHERE t.id = {$data['transaction_id']}
    ")->fetch_assoc();
    
    if ($txn_info) {
        // Activate the job listing
        $conn->query("UPDATE listings SET status = 'active', updated_at = NOW() WHERE id = {$txn_info['listing_id']}");
        $conn->query("UPDATE transactions SET status = 'completed', updated_at = NOW() WHERE id = {$data['transaction_id']}");
        $conn->query("UPDATE payment_codes SET status = 'used', updated_at = NOW() WHERE id = {$data['id']}");
        
        $response['job_activated'] = true;
        $response['listing_activated'] = true;
    }
}
// If payment is confirmed and listing is not active (for other types)
elseif ($data['is_paid'] && !$data['listing_active'] && $data['payment_code_type'] != 'commission') {
    // Get transaction and listing info for activation
    $txn_info = $conn->query("
        SELECT t.listing_id, t.id as transaction_id
        FROM transactions t
        WHERE t.id = {$data['transaction_id']}
    ")->fetch_assoc();
    
    if ($txn_info) {
        $conn->query("UPDATE listings SET status = 'active' WHERE id = {$txn_info['listing_id']}");
        $conn->query("UPDATE payment_codes SET status = 'used' WHERE id = {$data['id']}");
        $conn->query("UPDATE transactions SET status = 'deposits_complete' WHERE id = {$txn_info['transaction_id']}");
        $response['listing_activated'] = true;
    }
}

$conn->close();
echo json_encode($response);
?>