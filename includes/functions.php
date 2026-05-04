<?php
// includes/functions.php

function formatMoney($amount) {
    return number_format($amount, 2) . ' ETB';
}

function getStatusBadge($status) {
    $badges = [
        'pending' => '<span class="badge badge-warning">Pending</span>',
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

function getTransactionStatusBadge($status) {
    return getStatusBadge($status);
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

function generateCSRF() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCSRF($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}