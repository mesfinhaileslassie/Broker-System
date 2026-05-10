<?php
// admin/dashboard.php - Completely Redesigned Modern Admin Dashboard

require_once '../includes/auth.php';

if (!isLoggedIn() || $_SESSION['user_role'] != 'admin') {
    header('Location: /broker_system/auth/login.php');
    exit;
}

require_once '../config/database.php';
require_once '../includes/functions.php';

$conn = getDbConnection();
$admin_name = $_SESSION['user_name'];

// Get statistics
$stats = [
    'total_users' => $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'user'")->fetch_assoc()['count'],
    'total_companies' => $conn->query("SELECT COUNT(*) as count FROM companies")->fetch_assoc()['count'],
    'total_transactions' => $conn->query("SELECT COUNT(*) as count FROM transactions")->fetch_assoc()['count'],
    'completed_transactions' => $conn->query("SELECT COUNT(*) as count FROM transactions WHERE status = 'completed'")->fetch_assoc()['count'],
    'pending_transactions' => $conn->query("SELECT COUNT(*) as count FROM transactions WHERE status NOT IN ('completed', 'cancelled')")->fetch_assoc()['count'],
    'total_revenue' => $conn->query("SELECT SUM(commission_amount) as total FROM transactions WHERE status = 'completed'")->fetch_assoc()['total'] ?? 0,
    'pending_approvals' => $conn->query("SELECT COUNT(*) as count FROM listings WHERE approval_status = 'pending'")->fetch_assoc()['count'],
    'active_disputes' => $conn->query("SELECT COUNT(*) as count FROM disputes WHERE status IN ('open', 'under_review')")->fetch_assoc()['count'],
    'escrow_held' => $conn->query("SELECT SUM(escrow_held) as total FROM transactions WHERE status NOT IN ('completed', 'cancelled')")->fetch_assoc()['total'] ?? 0,
    'total_negotiations' => $conn->query("SELECT COUNT(*) as count FROM listing_negotiations")->fetch_assoc()['count'],
    'pending_negotiations' => $conn->query("SELECT COUNT(*) as count FROM listing_negotiations WHERE status IN ('under_review', 'commission_proposed', 'counter_offer_sent')")->fetch_assoc()['count'],
    'total_withdrawals' => $conn->query("SELECT SUM(amount) as total FROM withdrawal_requests WHERE status = 'completed'")->fetch_assoc()['total'] ?? 0,
    'new_users_today' => $conn->query("SELECT COUNT(*) as count FROM users WHERE DATE(created_at) = CURDATE()")->fetch_assoc()['count'],
];

// Get recent transactions
$recentTransactions = $conn->query("
    SELECT t.*, u1.full_name as buyer_name, u2.full_name as seller_name,
           l.title as listing_title
    FROM transactions t
    LEFT JOIN users u1 ON t.buyer_id = u1.id
    LEFT JOIN users u2 ON t.seller_id = u2.id
    LEFT JOIN listings l ON t.listing_id = l.id
    ORDER BY t.created_at DESC 
    LIMIT 8
");

// Get negotiations for table (UNIFIED TABLE VIEW)
$negotiations = $conn->query("
    SELECT ln.*, l.title, l.type, l.price, l.id as listing_id,
           u.full_name as seller_name, u.email as seller_email, u.id as seller_id,
           (SELECT COUNT(*) FROM negotiation_messages WHERE negotiation_id = ln.id AND is_read = 0 AND sender_type = 'seller') as unread_count
    FROM listing_negotiations ln
    JOIN listings l ON ln.listing_id = l.id
    JOIN users u ON ln.seller_id = u.id
    ORDER BY ln.created_at DESC
    LIMIT 15
");

// Get recent users
$recentUsers = $conn->query("
    SELECT id, full_name, email, role, is_verified, created_at 
    FROM users 
    ORDER BY created_at DESC 
    LIMIT 6
");

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Ethio Brokerplace</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: #f5f7fb;
            overflow-x: hidden;
        }

        /* ============================================
           SIDEBAR STYLES - Premium Design
        ============================================ */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 280px;
            height: 100%;
            background: linear-gradient(180deg, #0f172a 0%, #0f172a 100%);
            color: #e2e8f0;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 1050;
            overflow-y: auto;
            overflow-x: hidden;
            box-shadow: 4px 0 20px rgba(0,0,0,0.08);
        }

        /* Custom Scrollbar */
        .sidebar::-webkit-scrollbar { width: 4px; }
        .sidebar::-webkit-scrollbar-track { background: rgba(255,255,255,0.05); border-radius: 10px; }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 10px; }
        .sidebar::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.3); }

        /* Collapsed Sidebar */
        .sidebar.collapsed { width: 88px; }
        .sidebar.collapsed .logo-text,
        .sidebar.collapsed .menu-label,
        .sidebar.collapsed .profile-info,
        .sidebar.collapsed .section-header { display: none; }
        .sidebar.collapsed .menu-item { justify-content: center; padding: 12px; }
        .sidebar.collapsed .menu-item i { margin-right: 0; font-size: 1.4rem; }
        .sidebar.collapsed .logo { justify-content: center; }
        
        /* Sidebar Header */
        .sidebar-header {
            padding: 24px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            position: sticky;
            top: 0;
            background: #0f172a;
            z-index: 10;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .logo-icon {
            font-size: 28px;
            background: linear-gradient(135deg, #a57cff, #4f46e5);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .logo-text {
            font-size: 1.2rem;
            font-weight: 800;
            background: linear-gradient(135deg, #fff, #cbd5e1);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            letter-spacing: -0.3px;
        }

        .collapse-btn {
            background: rgba(255,255,255,0.08);
            border: none;
            color: #cbd5e1;
            width: 32px;
            height: 32px;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .collapse-btn:hover {
            background: rgba(255,255,255,0.18);
            color: white;
            transform: scale(1.05);
        }

        /* Navigation Menu */
        .nav-menu {
            list-style: none;
            padding: 20px 16px;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .menu-item {
            display: flex;
            align-items: center;
            padding: 12px 16px;
            border-radius: 14px;
            color: #94a3b8;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            gap: 12px;
        }

        .menu-item i {
            width: 24px;
            font-size: 1.2rem;
            text-align: center;
        }

        .menu-item span {
            font-size: 0.9rem;
            font-weight: 500;
        }

        .menu-item:hover {
            background: rgba(255,255,255,0.08);
            color: white;
            transform: translateX(4px);
        }

        .menu-item.active {
            background: linear-gradient(115deg, #4f46e5, #7c3aed);
            color: white;
            box-shadow: 0 4px 12px rgba(79,70,229,0.3);
        }

        .menu-item.active i {
            color: white;
        }

        .badge-count {
            background: #ef4444;
            color: white;
            font-size: 10px;
            font-weight: 600;
            padding: 2px 6px;
            border-radius: 20px;
            margin-left: auto;
            min-width: 20px;
            text-align: center;
        }

        .section-header {
            padding: 12px 16px 8px;
            margin-top: 12px;
            color: #475569;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 700;
        }

        /* Sidebar Footer */
        .sidebar-footer {
            position: sticky;
            bottom: 0;
            left: 0;
            right: 0;
            padding: 20px 16px;
            border-top: 1px solid rgba(255,255,255,0.08);
            background: #0f172a;
            margin-top: 20px;
        }

        .profile-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 12px;
            border-radius: 14px;
            text-decoration: none;
            color: #e2e8f0;
            transition: all 0.3s;
        }

        .profile-item:hover {
            background: rgba(255,255,255,0.08);
        }

        .profile-avatar {
            width: 42px;
            height: 42px;
            background: linear-gradient(145deg, #4f46e5, #6b21a5);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.1rem;
            box-shadow: 0 4px 10px rgba(0,0,0,0.2);
        }

        .profile-info {
            flex: 1;
            min-width: 0;
        }

        .profile-name {
            font-size: 0.85rem;
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .profile-email {
            font-size: 0.7rem;
            color: #94a3b8;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Mobile Menu Button */
        .mobile-menu-toggle {
            display: none;
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1060;
            background: #4f46e5;
            color: white;
            width: 45px;
            height: 45px;
            border-radius: 12px;
            border: none;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(79,70,229,0.3);
        }

        /* ============================================
           MAIN CONTENT STYLES
        ============================================ */
        .main-content {
            margin-left: 280px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            min-height: 100vh;
        }

        .main-content.expanded {
            margin-left: 88px;
        }

        /* Top Bar */
        .top-bar {
            background: white;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04), 0 2px 6px rgba(0,0,0,0.02);
        }

        .page-title {
            font-size: 1.5rem;
            font-weight: 800;
            background: linear-gradient(135deg, #0f172a, #2d3a5e);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            letter-spacing: -0.02em;
        }

        .admin-info {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .admin-badge {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 14px;
            background: #f1f5f9;
            border-radius: 40px;
            font-size: 0.8rem;
            font-weight: 600;
            color: #475569;
        }

        .admin-badge i {
            color: #4f46e5;
        }

        .logout-btn {
            padding: 8px 20px;
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
            border-radius: 40px;
            text-decoration: none;
            font-size: 0.8rem;
            font-weight: 600;
            transition: all 0.3s;
        }

        .logout-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(239,68,68,0.3);
        }

        .container {
            padding: 28px;
        }

        /* ============================================
           STATS CARDS
        ============================================ */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: 24px;
            padding: 1.5rem;
            transition: all 0.3s;
            border: 1px solid rgba(0,0,0,0.04);
            box-shadow: 0 2px 8px rgba(0,0,0,0.02);
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 25px -12px rgba(0,0,0,0.1);
        }

        .stat-icon {
            font-size: 2rem;
            margin-bottom: 1rem;
            display: inline-block;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.2;
        }

        .stat-label {
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #64748b;
            margin-top: 8px;
        }

        .stat-trend {
            font-size: 0.7rem;
            margin-top: 8px;
            color: #10b981;
            font-weight: 500;
        }

        /* ============================================
           SECTION CARDS
        ============================================ */
        .card {
            background: white;
            border-radius: 24px;
            margin-bottom: 2rem;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.02);
            border: 1px solid rgba(0,0,0,0.04);
        }

        .card-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 2px solid #f1f5f9;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .card-header h3 {
            font-size: 1rem;
            font-weight: 700;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-header h3 i {
            color: #4f46e5;
        }

        .card-header a {
            font-size: 0.75rem;
            color: #4f46e5;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
        }

        .card-header a:hover {
            text-decoration: underline;
        }

        /* ============================================
           UNIFIED NEGOTIATION TABLE - REDESIGNED
        ============================================ */
        .filters-bar {
            padding: 1rem 1.5rem;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .search-box {
            display: flex;
            align-items: center;
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 40px;
            padding: 0.5rem 1rem;
            gap: 0.5rem;
        }

        .search-box i {
            color: #94a3b8;
        }

        .search-box input {
            border: none;
            outline: none;
            font-size: 0.85rem;
            width: 250px;
        }

        .filter-tabs {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .filter-tab {
            padding: 0.4rem 1rem;
            border-radius: 40px;
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            background: white;
            border: 1px solid #e2e8f0;
            color: #64748b;
        }

        .filter-tab:hover, .filter-tab.active {
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            color: white;
            border-color: transparent;
        }

        .stats-mini {
            display: flex;
            gap: 1rem;
        }

        .stat-mini {
            text-align: center;
            padding: 0.5rem 1rem;
            background: white;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
        }

        .stat-mini-value {
            font-size: 1.2rem;
            font-weight: 800;
            color: #0f172a;
        }

        .stat-mini-label {
            font-size: 0.7rem;
            color: #64748b;
        }

        /* Premium Table Styles */
        .table-wrapper {
            overflow-x: auto;
            padding: 0 1.5rem 1.5rem 1.5rem;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }

        .data-table th {
            text-align: left;
            padding: 1rem 0.75rem;
            background: #f8fafc;
            font-weight: 600;
            color: #475569;
            border-bottom: 2px solid #e2e8f0;
        }

        .data-table td {
            padding: 1rem 0.75rem;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }

        .data-table tr {
            transition: all 0.2s;
        }

        .data-table tr:hover {
            background: #f8fafc;
        }

        /* Seller Info Cell */
        .seller-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .seller-avatar {
            width: 36px;
            height: 36px;
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.8rem;
            color: white;
            flex-shrink: 0;
        }

        .seller-details {
            line-height: 1.3;
        }

        .seller-name {
            font-weight: 600;
            color: #0f172a;
        }

        .seller-email {
            font-size: 0.7rem;
            color: #64748b;
        }

        /* Badges */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 0.25rem 0.7rem;
            border-radius: 40px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .badge-pending { background: #fef3c7; color: #92400e; }
        .badge-info { background: #dbeafe; color: #1e40af; }
        .badge-primary { background: #e0e7ff; color: #4f46e5; }
        .badge-warning { background: #fed7aa; color: #ea580c; }
        .badge-success { background: #d1fae5; color: #059669; }
        .badge-danger { background: #fee2e2; color: #dc2626; }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .btn-icon {
            padding: 0.4rem 0.8rem;
            border-radius: 8px;
            font-size: 0.7rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            border: none;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .btn-primary { background: #4f46e5; color: white; }
        .btn-primary:hover { background: #4338ca; transform: translateY(-1px); }

        .btn-success { background: #10b981; color: white; }
        .btn-success:hover { background: #059669; transform: translateY(-1px); }

        .btn-warning { background: #f59e0b; color: white; }
        .btn-warning:hover { background: #d97706; transform: translateY(-1px); }

        .btn-danger { background: #ef4444; color: white; }
        .btn-danger:hover { background: #dc2626; transform: translateY(-1px); }

        .btn-outline { background: transparent; border: 1px solid #e2e8f0; color: #64748b; }
        .btn-outline:hover { border-color: #4f46e5; color: #4f46e5; }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: flex-end;
            gap: 0.5rem;
            padding: 1rem 1.5rem;
            border-top: 1px solid #e2e8f0;
        }

        .page-btn {
            padding: 0.4rem 0.8rem;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            background: white;
            cursor: pointer;
            transition: all 0.3s;
        }

        .page-btn:hover, .page-btn.active {
            background: #4f46e5;
            color: white;
            border-color: #4f46e5;
        }

        /* Two Column Layout */
        .two-columns {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }

        /* Recent Users List */
        .users-list {
            padding: 0 1.5rem 1.5rem;
        }

        .user-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.75rem 0;
            border-bottom: 1px solid #f1f5f9;
        }

        .user-item:last-child {
            border-bottom: none;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
        }

        /* Chart Container */
        .chart-container {
            padding: 1.5rem;
        }

        canvas {
            max-height: 300px;
            width: 100% !important;
        }

        /* Alert Banner */
        .alert-banner {
            background: #fffbeb;
            border-radius: 16px;
            padding: 1rem 1.5rem;
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            border-left: 4px solid #f59e0b;
        }

        .alert-content {
            display: flex;
            align-items: center;
            gap: 12px;
            color: #92400e;
            font-weight: 500;
        }

        .alert-btn {
            background: #f59e0b;
            color: white;
            padding: 0.5rem 1.2rem;
            border-radius: 40px;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.8rem;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #64748b;
        }

        .empty-state i {
            font-size: 3rem;
            color: #cbd5e1;
            margin-bottom: 1rem;
            display: block;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .sidebar { width: 88px; }
            .sidebar .logo-text, .sidebar .menu-label, .sidebar .profile-info, .sidebar .section-header { display: none; }
            .sidebar .menu-item { justify-content: center; padding: 12px; }
            .sidebar .menu-item i { margin-right: 0; font-size: 1.4rem; }
            .main-content { margin-left: 88px; }
            .two-columns { grid-template-columns: 1fr; gap: 1rem; }
        }

        @media (max-width: 768px) {
            .mobile-menu-toggle {
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .sidebar {
                transform: translateX(-100%);
                width: 280px;
            }
            .sidebar.mobile-open {
                transform: translateX(0);
            }
            .sidebar.mobile-open .logo-text,
            .sidebar.mobile-open .menu-label,
            .sidebar.mobile-open .profile-info,
            .sidebar.mobile-open .section-header {
                display: block;
            }
            .sidebar.mobile-open .menu-item {
                justify-content: flex-start;
                padding: 12px 16px;
            }
            .sidebar.mobile-open .menu-item i {
                margin-right: 12px;
            }
            .main-content {
                margin-left: 0;
            }
            .top-bar {
                padding: 1rem;
                flex-wrap: wrap;
                gap: 1rem;
            }
            .container {
                padding: 1rem;
            }
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 1rem;
            }
            .filters-bar {
                flex-direction: column;
                align-items: stretch;
            }
            .search-box input {
                width: 100%;
            }
            .filter-tabs {
                justify-content: center;
            }
            .stats-mini {
                justify-content: center;
            }
            .action-buttons {
                flex-wrap: wrap;
            }
            .table-wrapper {
                overflow-x: auto;
            }
            .data-table {
                min-width: 800px;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            .stat-value {
                font-size: 1.5rem;
            }
            .page-title {
                font-size: 1.2rem;
            }
        }
    </style>
</head>
<body>

    <!-- Mobile Menu Toggle -->
    <button class="mobile-menu-toggle" id="mobileMenuToggle">
        <i class="fas fa-bars"></i>
    </button>

    <!-- SIDEBAR -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <span class="logo-icon">🏪</span>
                <span class="logo-text">Brokerplace</span>
            </div>
            <button class="collapse-btn" id="collapseBtn">
                <i class="fas fa-chevron-left"></i>
            </button>
        </div>

        <ul class="nav-menu">
            <div class="section-header">Main</div>
            <a href="dashboard.php" class="menu-item active">
                <i class="fas fa-chart-line"></i>
                <span class="menu-label">Dashboard</span>
            </a>
            <a href="users.php" class="menu-item">
                <i class="fas fa-users"></i>
                <span class="menu-label">Users</span>
            </a>
            <a href="transactions.php" class="menu-item">
                <i class="fas fa-exchange-alt"></i>
                <span class="menu-label">Transactions</span>
            </a>
            
            <div class="section-header">Management</div>
            <a href="approve_listings.php" class="menu-item">
                <i class="fas fa-check-double"></i>
                <span class="menu-label">Approve Listings</span>
                <?php if ($stats['pending_approvals'] > 0): ?>
                    <span class="badge-count"><?php echo $stats['pending_approvals']; ?></span>
                <?php endif; ?>
            </a>
            <a href="negotiations.php" class="menu-item">
                <i class="fas fa-handshake"></i>
                <span class="menu-label">Negotiations</span>
                <?php if ($stats['pending_negotiations'] > 0): ?>
                    <span class="badge-count"><?php echo $stats['pending_negotiations']; ?></span>
                <?php endif; ?>
            </a>
            <a href="disputes.php" class="menu-item">
                <i class="fas fa-gavel"></i>
                <span class="menu-label">Disputes</span>
                <?php if ($stats['active_disputes'] > 0): ?>
                    <span class="badge-count"><?php echo $stats['active_disputes']; ?></span>
                <?php endif; ?>
            </a>
            <a href="withdrawals.php" class="menu-item">
                <i class="fas fa-money-bill-wave"></i>
                <span class="menu-label">Withdrawals</span>
            </a>
            <a href="escrow_management.php" class="menu-item">
                <i class="fas fa-shield-alt"></i>
                <span class="menu-label">Escrow</span>
            </a>
            
            <div class="section-header">Settings</div>
            <a href="settings.php" class="menu-item">
                <i class="fas fa-cog"></i>
                <span class="menu-label">Settings</span>
            </a>
        </ul>

        <div class="sidebar-footer">
            <div class="profile-item">
                <div class="profile-avatar"><?php echo strtoupper(substr($admin_name, 0, 1)); ?></div>
                <div class="profile-info">
                    <div class="profile-name"><?php echo htmlspecialchars($admin_name); ?></div>
                    <div class="profile-email">Administrator</div>
                </div>
            </div>
            <a href="../auth/logout.php" class="menu-item" style="margin-top: 8px;">
                <i class="fas fa-sign-out-alt"></i>
                <span class="menu-label">Logout</span>
            </a>
        </div>
    </div>

    <!-- MAIN CONTENT -->
    <div class="main-content" id="mainContent">
        <div class="top-bar">
            <h1 class="page-title"><i class="fas fa-tachometer-alt"></i> Admin Dashboard</h1>
            <div class="admin-info">
                <div class="admin-badge">
                    <i class="fas fa-user-shield"></i>
                    <span>Super Admin</span>
                </div>
                <a href="../auth/logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i> Exit
                </a>
            </div>
        </div>

        <div class="container">
            
            <!-- Alert Banner -->
            <?php if ($stats['pending_negotiations'] > 0 || $stats['pending_approvals'] > 0): ?>
            <div class="alert-banner">
                <div class="alert-content">
                    <i class="fas fa-bell"></i>
                    <span><strong><?php echo $stats['pending_negotiations']; ?> negotiation(s)</strong> and <strong><?php echo $stats['pending_approvals']; ?> listing(s)</strong> require your attention</span>
                </div>
                <a href="negotiations.php" class="alert-btn">Review Now →</a>
            </div>
            <?php endif; ?>

            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">👥</div>
                    <div class="stat-value"><?php echo number_format($stats['total_users']); ?></div>
                    <div class="stat-label">Total Users</div>
                    <div class="stat-trend"><i class="fas fa-plus-circle"></i> +<?php echo $stats['new_users_today']; ?> today</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">🏢</div>
                    <div class="stat-value"><?php echo number_format($stats['total_companies']); ?></div>
                    <div class="stat-label">Companies</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">🔄</div>
                    <div class="stat-value"><?php echo number_format($stats['total_transactions']); ?></div>
                    <div class="stat-label">Transactions</div>
                    <div class="stat-trend"><?php echo $stats['pending_transactions']; ?> pending</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">💰</div>
                    <div class="stat-value"><?php echo formatMoney($stats['total_revenue']); ?></div>
                    <div class="stat-label">Total Revenue</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">🔒</div>
                    <div class="stat-value"><?php echo formatMoney($stats['escrow_held']); ?></div>
                    <div class="stat-label">Escrow Held</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">🤝</div>
                    <div class="stat-value"><?php echo $stats['pending_negotiations']; ?></div>
                    <div class="stat-label">Active Negotiations</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">⚖️</div>
                    <div class="stat-value"><?php echo $stats['active_disputes']; ?></div>
                    <div class="stat-label">Active Disputes</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">💸</div>
                    <div class="stat-value"><?php echo formatMoney($stats['total_withdrawals']); ?></div>
                    <div class="stat-label">Withdrawals Processed</div>
                </div>
            </div>

            <!-- Commission Negotiations Table - REDESIGNED -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-handshake"></i> Commission Negotiations</h3>
                    <a href="negotiations.php">View All Negotiations →</a>
                </div>
                
                <!-- Filters Bar -->
                <div class="filters-bar">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" id="searchNegotiations" placeholder="Search by listing or seller...">
                    </div>
                    <div class="filter-tabs">
                        <button class="filter-tab active" data-filter="all">All</button>
                        <button class="filter-tab" data-filter="under_review">Pending Review</button>
                        <button class="filter-tab" data-filter="commission_proposed">Awaiting Response</button>
                        <button class="filter-tab" data-filter="counter_offer_sent">Counter Offers</button>
                        <button class="filter-tab" data-filter="agreement_accepted">Payment Due</button>
                        <button class="filter-tab" data-filter="published">Published</button>
                    </div>
                    <div class="stats-mini">
                        <div class="stat-mini">
                            <div class="stat-mini-value"><?php echo $stats['pending_negotiations']; ?></div>
                            <div class="stat-mini-label">Active</div>
                        </div>
                    </div>
                </div>

                <div class="table-wrapper">
                    <table class="data-table" id="negotiationsTable">
                        <thead>
                            <tr>
                                <th>Listing</th>
                                <th>Seller</th>
                                <th>Price</th>
                                <th>Proposed</th>
                                <th>Agreed</th>
                                <th>Deposit</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($negotiations && $negotiations->num_rows > 0): ?>
                                <?php while($neg = $negotiations->fetch_assoc()): 
                                    $status_class = '';
                                    $status_text = '';
                                    switch($neg['status']) {
                                        case 'under_review':
                                            $status_class = 'badge-pending';
                                            $status_text = 'Pending Review';
                                            break;
                                        case 'commission_proposed':
                                            $status_class = 'badge-info';
                                            $status_text = 'Awaiting Response';
                                            break;
                                        case 'counter_offer_sent':
                                            $status_class = 'badge-primary';
                                            $status_text = 'Counter Offer';
                                            break;
                                        case 'agreement_accepted':
                                            $status_class = 'badge-warning';
                                            $status_text = 'Payment Due';
                                            break;
                                        case 'published':
                                            $status_class = 'badge-success';
                                            $status_text = 'Published';
                                            break;
                                        default:
                                            $status_class = 'badge-pending';
                                            $status_text = ucfirst(str_replace('_', ' ', $neg['status']));
                                    }
                                ?>
                                    <tr data-status="<?php echo $neg['status']; ?>">
                                        <td>
                                            <strong><?php echo htmlspecialchars(substr($neg['title'], 0, 35)); ?></strong>
                                            <div style="font-size: 0.7rem; color: #64748b;"><?php echo ucfirst($neg['type']); ?></div>
                                        </td>
                                        <td>
                                            <div class="seller-info">
                                                <div class="seller-avatar"><?php echo strtoupper(substr($neg['seller_name'], 0, 1)); ?></div>
                                                <div class="seller-details">
                                                    <div class="seller-name"><?php echo htmlspecialchars($neg['seller_name']); ?></div>
                                                    <div class="seller-email"><?php echo htmlspecialchars($neg['seller_email']); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="stat-value" style="font-size: 0.9rem;"><?php echo formatMoney($neg['price']); ?></td>
                                        <td><?php echo $neg['proposed_commission'] ? $neg['proposed_commission'] . '%' : '—'; ?></td>
                                        <td><?php echo $neg['counter_commission'] ?: ($neg['proposed_commission'] ? $neg['proposed_commission'] . '%' : '—'); ?></td>
                                        <td><?php echo $neg['proposed_deposit'] ? formatMoney($neg['proposed_deposit']) : '—'; ?></td>
                                        <td><span class="badge <?php echo $status_class; ?>"><?php echo $status_text; ?></span></td>
                                        <td style="font-size: 0.75rem;"><?php echo date('M d, Y', strtotime($neg['created_at'])); ?></td>
                                        <td>
                                            <div class="action-buttons">
                                                <?php if ($neg['status'] == 'under_review'): ?>
                                                    <a href="negotiations.php?action=propose&id=<?php echo $neg['id']; ?>" class="btn-icon btn-primary">
                                                        <i class="fas fa-percent"></i> Propose
                                                    </a>
                                                <?php elseif ($neg['status'] == 'commission_proposed'): ?>
                                                    <a href="negotiations.php?action=view&id=<?php echo $neg['id']; ?>" class="btn-icon btn-primary">
                                                        <i class="fas fa-eye"></i> View
                                                    </a>
                                                <?php elseif ($neg['status'] == 'counter_offer_sent'): ?>
                                                    <a href="negotiations.php?action=view&id=<?php echo $neg['id']; ?>" class="btn-icon btn-primary">
                                                        <i class="fas fa-exchange-alt"></i> Review
                                                    </a>
                                                <?php elseif ($neg['status'] == 'agreement_accepted'): ?>
                                                    <a href="negotiations.php?action=verify&id=<?php echo $neg['id']; ?>" class="btn-icon btn-success">
                                                        <i class="fas fa-check-circle"></i> Verify
                                                    </a>
                                                <?php elseif ($neg['status'] == 'published'): ?>
                                                    <a href="/broker_system/user/product.php?id=<?php echo $neg['listing_id']; ?>" target="_blank" class="btn-icon btn-outline">
                                                        <i class="fas fa-eye"></i> View
                                                    </a>
                                                <?php endif; ?>
                                                <a href="javascript:void(0)" onclick="contactSeller(<?php echo $neg['seller_id']; ?>)" class="btn-icon btn-outline">
                                                    <i class="fas fa-comment"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" class="empty-state">
                                        <i class="fas fa-handshake"></i>
                                        <p>No negotiations found</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="pagination">
                    <button class="page-btn">1</button>
                    <button class="page-btn">2</button>
                    <button class="page-btn">3</button>
                    <button class="page-btn">Next →</button>
                </div>
            </div>

            <!-- Two Column Layout -->
            <div class="two-columns">
                
                <!-- Recent Transactions -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-clock"></i> Recent Transactions</h3>
                        <a href="transactions.php">View All →</a>
                    </div>
                    <div class="table-wrapper" style="padding: 0 1.5rem 1.5rem 1.5rem;">
                        <?php if ($recentTransactions->num_rows > 0): ?>
                            <table class="data-table">
                                <thead>
                                    <tr><th>ID</th><th>Item</th><th>Amount</th><th>Status</th><th>Date</th></tr>
                                </thead>
                                <tbody>
                                    <?php while($row = $recentTransactions->fetch_assoc()): ?>
                                    <tr>
                                        <td>#<?php echo $row['id']; ?></td>
                                        <td><?php echo htmlspecialchars(substr($row['listing_title'] ?? 'N/A', 0, 20)); ?></td>
                                        <td><?php echo formatMoney($row['total_amount']); ?></td>
                                        <td><?php echo getStatusBadge($row['status']); ?></td>
                                        <td style="font-size: 0.7rem;"><?php echo date('M d', strtotime($row['created_at'])); ?></td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="empty-state"><i class="fas fa-inbox"></i><p>No recent transactions</p></div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Users -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-user-plus"></i> Newest Members</h3>
                        <a href="users.php">Manage Users →</a>
                    </div>
                    <div class="users-list">
                        <?php if ($recentUsers->num_rows > 0): ?>
                            <?php while($user = $recentUsers->fetch_assoc()): ?>
                            <div class="user-item">
                                <div class="user-info">
                                    <div class="user-avatar"><?php echo strtoupper(substr($user['full_name'], 0, 1)); ?></div>
                                    <div>
                                        <div class="seller-name"><?php echo htmlspecialchars($user['full_name']); ?></div>
                                        <div class="seller-email"><?php echo htmlspecialchars($user['email']); ?></div>
                                    </div>
                                </div>
                                <div>
                                    <span class="badge <?php echo $user['is_verified'] ? 'badge-success' : 'badge-pending'; ?>">
                                        <?php echo $user['is_verified'] ? 'Verified' : 'Unverified'; ?>
                                    </span>
                                </div>
                            </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div class="empty-state"><i class="fas fa-users"></i><p>No recent signups</p></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Revenue Chart -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-chart-line"></i> Revenue Overview (Last 7 Days)</h3>
                </div>
                <div class="chart-container">
                    <canvas id="revenueChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Sidebar collapse functionality
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const collapseBtn = document.getElementById('collapseBtn');
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');

        if (collapseBtn) {
            collapseBtn.addEventListener('click', () => {
                sidebar.classList.toggle('collapsed');
                mainContent.classList.toggle('expanded');
                const icon = collapseBtn.querySelector('i');
                if (sidebar.classList.contains('collapsed')) {
                    icon.classList.remove('fa-chevron-left');
                    icon.classList.add('fa-chevron-right');
                } else {
                    icon.classList.remove('fa-chevron-right');
                    icon.classList.add('fa-chevron-left');
                }
                localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
            });
        }

        // Load saved sidebar state
        if (localStorage.getItem('sidebarCollapsed') === 'true') {
            sidebar.classList.add('collapsed');
            mainContent.classList.add('expanded');
            if (collapseBtn) {
                const icon = collapseBtn.querySelector('i');
                icon.classList.remove('fa-chevron-left');
                icon.classList.add('fa-chevron-right');
            }
        }

        // Mobile sidebar toggle
        if (mobileMenuToggle) {
            mobileMenuToggle.addEventListener('click', () => {
                sidebar.classList.toggle('mobile-open');
            });
        }

        // Close mobile sidebar when clicking outside
        document.addEventListener('click', (e) => {
            if (window.innerWidth <= 768) {
                if (!sidebar.contains(e.target) && !mobileMenuToggle.contains(e.target)) {
                    sidebar.classList.remove('mobile-open');
                }
            }
        });

        // Filter negotiations table
        const filterTabs = document.querySelectorAll('.filter-tab');
        const tableRows = document.querySelectorAll('#negotiationsTable tbody tr');
        const searchInput = document.getElementById('searchNegotiations');

        filterTabs.forEach(tab => {
            tab.addEventListener('click', () => {
                filterTabs.forEach(t => t.classList.remove('active'));
                tab.classList.add('active');
                const filter = tab.dataset.filter;
                
                tableRows.forEach(row => {
                    if (filter === 'all' || row.dataset.status === filter) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                });
            });
        });

        // Search functionality
        if (searchInput) {
            searchInput.addEventListener('keyup', () => {
                const searchTerm = searchInput.value.toLowerCase();
                tableRows.forEach(row => {
                    const text = row.textContent.toLowerCase();
                    if (text.includes(searchTerm)) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                });
            });
        }

        // Contact seller function
        function contactSeller(sellerId) {
            window.open(`../user/chat.php?user=${sellerId}`, '_blank');
        }

        // Revenue Chart
        const ctx = document.getElementById('revenueChart').getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: ['Day 6', 'Day 5', 'Day 4', 'Day 3', 'Day 2', 'Yesterday', 'Today'],
                datasets: [{
                    label: 'Revenue (ETB)',
                    data: [12500, 18900, 15200, 22400, 19800, 27500, 31200],
                    borderColor: '#4f46e5',
                    backgroundColor: 'rgba(79,70,229,0.1)',
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: '#4f46e5',
                    pointBorderColor: 'white',
                    pointRadius: 6,
                    pointHoverRadius: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'top',
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return value.toLocaleString() + ' ETB';
                            }
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>

<?php

?>