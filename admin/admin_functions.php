<?php
// includes/admin_functions.php

require_once __DIR__ . '/../config/database.php';

function getAdminStats($conn) {
    $stats = [];
    
    // Total users
    $result = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'user'");
    $stats['total_users'] = $result->fetch_assoc()['count'];
    
    // Total companies
    $result = $conn->query("SELECT COUNT(*) as count FROM companies");
    $stats['total_companies'] = $result->fetch_assoc()['count'];
    
    // Total transactions
    $result = $conn->query("SELECT COUNT(*) as count FROM transactions");
    $stats['total_transactions'] = $result->fetch_assoc()['count'];
    
    // Pending transactions
    $result = $conn->query("SELECT COUNT(*) as count FROM transactions WHERE status NOT IN ('completed', 'cancelled')");
    $stats['pending_transactions'] = $result->fetch_assoc()['count'];
    
    // Active disputes
    $result = $conn->query("SELECT COUNT(*) as count FROM disputes WHERE status IN ('open', 'under_review')");
    $stats['active_disputes'] = $result->fetch_assoc()['count'];
    
    // Total revenue (commission collected)
    $result = $conn->query("SELECT SUM(commission_amount) as total FROM transactions WHERE status = 'completed'");
    $stats['total_revenue'] = $result->fetch_assoc()['total'] ?? 0;
    
    // Escrow held
    $result = $conn->query("SELECT SUM(escrow_held) as total FROM transactions WHERE status NOT IN ('completed', 'cancelled')");
    $stats['escrow_held'] = $result->fetch_assoc()['total'] ?? 0;
    
    // Recent users (last 7 days)
    $result = $conn->query("SELECT COUNT(*) as count FROM users WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $stats['new_users_7d'] = $result->fetch_assoc()['count'];
    
    // Total listings
    $result = $conn->query("SELECT COUNT(*) as count FROM listings WHERE status = 'active'");
    $stats['active_listings'] = $result->fetch_assoc()['count'];
    
    return $stats;
}

function getRecentTransactions($conn, $limit = 10) {
    $sql = "SELECT t.*, u1.full_name as buyer_name, u2.full_name as seller_name 
            FROM transactions t
            LEFT JOIN users u1 ON t.buyer_id = u1.id
            LEFT JOIN users u2 ON t.seller_id = u2.id
            ORDER BY t.created_at DESC 
            LIMIT $limit";
    return $conn->query($sql);
}

function getRecentUsers($conn, $limit = 10) {
    $sql = "SELECT * FROM users ORDER BY created_at DESC LIMIT $limit";
    return $conn->query($sql);
}

function getRecentDisputes($conn, $limit = 5) {
    // Fixed: Changed 'd.created_at' to 'd.created_at' (it exists) but added proper table alias
    // Make sure disputes table has created_at column
    $sql = "SELECT d.*, t.total_amount, u.full_name as raised_by_name 
            FROM disputes d
            JOIN transactions t ON d.transaction_id = t.id
            JOIN users u ON d.raised_by = u.id
            ORDER BY d.created_at DESC 
            LIMIT $limit";
    return $conn->query($sql);
}