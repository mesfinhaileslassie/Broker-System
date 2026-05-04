<?php
// includes/functions.php - Complete with all needed functions

require_once __DIR__ . '/../config/database.php';

function formatMoney($amount) {
    return number_format($amount, 2) . ' ETB';
}

function getStatusBadge($status) {
    $badges = [
        'pending' => '<span class="badge badge-warning">Pending</span>',
        'pending_deposit' => '<span class="badge badge-warning">Pending Deposit</span>',
        'awaiting_buyer_deposit' => '<span class="badge badge-info">Awaiting Buyer Deposit</span>',
        'awaiting_seller_deposit' => '<span class="badge badge-info">Awaiting Seller Deposit</span>',
        'deposits_complete' => '<span class="badge badge-primary">Deposits Complete</span>',
        'in_progress' => '<span class="badge badge-info">In Progress</span>',
        'completed' => '<span class="badge badge-success">Completed</span>',
        'disputed' => '<span class="badge badge-danger">Disputed</span>',
        'cancelled' => '<span class="badge badge-secondary">Cancelled</span>'
    ];
    
    return $badges[$status] ?? '<span class="badge badge-secondary">' . $status . '</span>';
}

function getUserRoleBadge($role) {
    $badges = [
        'admin' => '<span class="badge badge-danger">Admin</span>',
        'user' => '<span class="badge badge-primary">User</span>',
        'company' => '<span class="badge badge-info">Company</span>'
    ];
    
    return $badges[$role] ?? '<span class="badge badge-secondary">' . $role . '</span>';
}

function getVerificationBadge($isVerified) {
    if ($isVerified) {
        return '<span class="badge badge-success">✓ Verified</span>';
    }
    return '<span class="badge badge-warning">⚠ Pending</span>';
}

function timeAgo($timestamp) {
    $time = strtotime($timestamp);
    $diff = time() - $time;
    
    if ($diff < 60) return $diff . ' seconds ago';
    if ($diff < 3600) return floor($diff / 60) . ' minutes ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hours ago';
    if ($diff < 2592000) return floor($diff / 86400) . ' days ago';
    return date('M d, Y', $time);
}

// Get setting from database
function getSetting($key, $default = null) {
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $conn->close();
        return $row['setting_value'];
    }
    
    $conn->close();
    
    // Default settings
    $defaults = [
        'deposit_percent' => 30,
        'commission_percent' => 15,
        'escrow_days' => 14,
        'site_name' => 'Ethio Brokerplace',
        'min_withdrawal' => 100,
        'max_withdrawal' => 100000,
        'maintenance_mode' => 0
    ];
    
    return $defaults[$key] ?? $default;
}

function updateSetting($key, $value) {
    $conn = getDbConnection();
    $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_at) 
                            VALUES (?, ?, NOW()) 
                            ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()");
    $stmt->bind_param("sss", $key, $value, $value);
    $result = $stmt->execute();
    $conn->close();
    return $result;
}

function generateCSRF() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCSRF($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function logAdminAction($conn, $admin_id, $action, $target_type, $target_id, $details, $ip) {
    $stmt = $conn->prepare("INSERT INTO admin_logs (admin_id, action, target_type, target_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ississ", $admin_id, $action, $target_type, $target_id, $details, $ip);
    $stmt->execute();
}