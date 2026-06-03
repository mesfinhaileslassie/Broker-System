<?php
// admin/approve_listings.php - Complete Admin Approval System with Commission & Deposit

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check admin login
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: /broker_system/auth/login.php');
    exit;
}

require_once '../config/database.php';
require_once '../includes/functions.php';

$conn = getDbConnection();
$admin_name = $_SESSION['admin_name'] ?? 'Admin';
$message = '';
$error = '';

// Handle approval/rejection actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $listing_id = intval($_POST['listing_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $admin_notes = isset($_POST['admin_notes']) ? $conn->real_escape_string(trim($_POST['admin_notes'])) : '';
    
    if ($action === 'approve_with_commission') {
        $commission_percent = floatval($_POST['commission_percent'] ?? 0);
        $deposit_amount = floatval($_POST['deposit_amount'] ?? 0);
        
        // Validate commission and deposit
        if ($commission_percent <= 0) {
            $error = "Commission percentage is required and must be greater than 0";
        } elseif ($deposit_amount <= 0) {
            $error = "Deposit amount is required and must be greater than 0";
        } else {
            // Update listing with commission and deposit settings
            $conn->query("
                UPDATE listings 
                SET approval_status = 'approved',
                    admin_commission_percent = $commission_percent,
                    admin_deposit_percent = $commission_percent,
                    admin_notes = '$admin_notes',
                    approved_at = NOW(),
                    approved_by = {$_SESSION['admin_id']}
                WHERE id = $listing_id
            ");
            
            // Create or update negotiation record
            $listing = $conn->query("SELECT seller_id, title, price FROM listings WHERE id = $listing_id")->fetch_assoc();
            
            // Check if negotiation exists
            $neg_check = $conn->query("SELECT id FROM listing_negotiations WHERE listing_id = $listing_id");
            if ($neg_check->num_rows > 0) {
                $neg_id = $neg_check->fetch_assoc()['id'];
                $conn->query("
                    UPDATE listing_negotiations 
                    SET proposed_commission = $commission_percent,
                        proposed_deposit = $deposit_amount,
                        status = 'agreement_accepted',
                        accepted_at = NOW(),
                        updated_at = NOW()
                    WHERE id = $neg_id
                ");
            } else {
                $conn->query("
                    INSERT INTO listing_negotiations (listing_id, seller_id, proposed_commission, proposed_deposit, status, created_at, updated_at) 
                    VALUES ($listing_id, {$listing['seller_id']}, $commission_percent, $deposit_amount, 'agreement_accepted', NOW(), NOW())
                ");
            }
            
            // Notify seller
            $conn->query("
                INSERT INTO notifications (user_id, title, message, link, created_at) 
                VALUES ({$listing['seller_id']}, '✅ Listing Approved', 
                'Congratulations! Your listing \"{$listing['title']}\" has been approved with {$commission_percent}% commission and " . formatMoney($deposit_amount) . " deposit. Please pay the deposit to publish your listing.', 
                '/broker_system/user/listings.php', NOW())
            ");
            
            $message = "Listing #$listing_id has been approved with {$commission_percent}% commission and " . formatMoney($deposit_amount) . " deposit!";
        }
        
    } elseif ($action === 'reject') {
        $conn->query("
            UPDATE listings 
            SET approval_status = 'rejected', 
                admin_notes = '$admin_notes',
                rejected_at = NOW(),
                rejected_by = {$_SESSION['admin_id']}
            WHERE id = $listing_id
        ");
        
        $listing = $conn->query("SELECT title, seller_id FROM listings WHERE id = $listing_id")->fetch_assoc();
        
        $conn->query("
            INSERT INTO notifications (user_id, title, message, link, created_at) 
            VALUES ({$listing['seller_id']}, '❌ Listing Not Approved', 
            'Your listing \"{$listing['title']}\" was not approved. Reason: " . substr($admin_notes, 0, 200) . "', 
            '/broker_system/user/listings.php', NOW())
        ");
        
        $message = "Listing #$listing_id has been rejected.";
    }
}

// Get filter parameter
$filter = $_GET['filter'] ?? 'pending';
$search = isset($_GET['search']) ? $conn->real_escape_string(trim($_GET['search'])) : '';
$type_filter = isset($_GET['type']) ? $conn->real_escape_string(trim($_GET['type'])) : '';

// Build query
$where = "1=1";
if ($filter === 'pending') {
    $where = "l.approval_status = 'pending'";
} elseif ($filter === 'approved') {
    $where = "l.approval_status = 'approved' AND l.status = 'active'";
} elseif ($filter === 'rejected') {
    $where = "l.approval_status = 'rejected'";
} elseif ($filter === 'all') {
    $where = "1=1";
}

if ($type_filter && $type_filter !== 'all') {
    $where .= " AND l.type = '$type_filter'";
}

if ($search) {
    $where .= " AND (l.title LIKE '%$search%' OR l.description LIKE '%$search%' OR u.full_name LIKE '%$search%')";
}

// Get listings
$listings = $conn->query("
    SELECT l.*, u.full_name as seller_name, u.email as seller_email, u.phone as seller_phone,
           c.name as category_name,
           l.admin_deposit_percent, l.admin_commission_percent
    FROM listings l
    JOIN users u ON l.seller_id = u.id
    LEFT JOIN categories c ON l.category_id = c.id
    WHERE $where
    ORDER BY l.created_at DESC
");

// Get statistics
$stats = [
    'pending' => $conn->query("SELECT COUNT(*) as count FROM listings WHERE approval_status = 'pending'")->fetch_assoc()['count'] ?? 0,
    'approved' => $conn->query("SELECT COUNT(*) as count FROM listings WHERE approval_status = 'approved' AND status = 'active'")->fetch_assoc()['count'] ?? 0,
    'rejected' => $conn->query("SELECT COUNT(*) as count FROM listings WHERE approval_status = 'rejected'")->fetch_assoc()['count'] ?? 0,
    'total' => $conn->query("SELECT COUNT(*) as count FROM listings")->fetch_assoc()['count'] ?? 0,
];

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approve Listings - Admin Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Inter', sans-serif; 
            background: linear-gradient(135deg, #f5f7fa 0%, #eef2f6 100%);
            min-height: 100vh;
        }

        /* Sidebar */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 280px;
            height: 100%;
            background: linear-gradient(180deg, #0f172a 0%, #1e293b 100%);
            color: #e2e8f0;
            transition: all 0.3s ease;
            z-index: 1050;
            overflow-y: auto;
            box-shadow: 4px 0 25px rgba(0,0,0,0.1);
        }
        .sidebar::-webkit-scrollbar { width: 4px; }
        .sidebar::-webkit-scrollbar-track { background: rgba(255,255,255,0.05); }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 10px; }
        .sidebar.collapsed { width: 88px; }
        .sidebar.collapsed .logo-text, .sidebar.collapsed .menu-label, .sidebar.collapsed .profile-info, .sidebar.collapsed .section-header { display: none; }
        .sidebar.collapsed .menu-item { justify-content: center; padding: 12px; }
        .sidebar.collapsed .menu-item i { margin-right: 0; font-size: 1.4rem; }
        
        .sidebar-header { padding: 24px 20px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid rgba(255,255,255,0.08); background: #0f172a; }
        .logo { display: flex; align-items: center; gap: 12px; }
        .logo-icon { width: 40px; height: 40px; background: linear-gradient(135deg, #667eea, #764ba2); border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 20px; }
        .logo-text { font-size: 1.2rem; font-weight: 800; color: white; }
        .collapse-btn { background: rgba(255,255,255,0.08); border: none; color: #cbd5e1; width: 32px; height: 32px; border-radius: 10px; cursor: pointer; transition: all 0.3s; }
        .collapse-btn:hover { background: rgba(255,255,255,0.18); color: white; }

        .nav-menu { list-style: none; padding: 20px 16px; display: flex; flex-direction: column; gap: 6px; }
        .section-header { padding: 12px 16px 8px; margin-top: 8px; color: #5b6e8c; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 1.5px; font-weight: 700; }
        .menu-item { display: flex; align-items: center; padding: 12px 16px; border-radius: 12px; color: #94a3b8; transition: all 0.3s; text-decoration: none; gap: 12px; }
        .menu-item i { width: 22px; font-size: 1.1rem; }
        .menu-item span { font-size: 0.85rem; font-weight: 500; }
        .menu-item:hover { background: rgba(255,255,255,0.08); color: white; transform: translateX(5px); }
        .menu-item.active { background: linear-gradient(115deg, #667eea, #764ba2); color: white; box-shadow: 0 4px 12px rgba(102,126,234,0.3); }
        .badge-count { background: #ef4444; color: white; font-size: 10px; font-weight: 600; padding: 2px 8px; border-radius: 20px; margin-left: auto; }

        .sidebar-footer { position: sticky; bottom: 0; padding: 20px 16px; border-top: 1px solid rgba(255,255,255,0.08); background: #0f172a; margin-top: 20px; }
        .profile-item { display: flex; align-items: center; gap: 12px; padding: 10px 12px; border-radius: 12px; text-decoration: none; color: #e2e8f0; }
        .profile-item:hover { background: rgba(255,255,255,0.08); }
        .profile-avatar { width: 42px; height: 42px; background: linear-gradient(135deg, #667eea, #764ba2); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; }
        .profile-name { font-size: 0.85rem; font-weight: 600; }
        .profile-email { font-size: 0.7rem; color: #94a3b8; }

        .mobile-menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1060; background: #667eea; color: white; width: 45px; height: 45px; border-radius: 12px; border: none; cursor: pointer; box-shadow: 0 4px 12px rgba(102,126,234,0.3); }

        /* Main Content */
        .main-content { margin-left: 280px; transition: all 0.3s ease; min-height: 100vh; }
        .main-content.expanded { margin-left: 88px; }

        .top-bar {
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(10px);
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 100;
            border-bottom: 1px solid rgba(0,0,0,0.05);
        }
        .page-title { font-size: 1.5rem; font-weight: 800; background: linear-gradient(135deg, #1e293b, #475569); -webkit-background-clip: text; background-clip: text; color: transparent; }
        .admin-info { display: flex; align-items: center; gap: 1.5rem; }
        .admin-badge { display: flex; align-items: center; gap: 8px; padding: 6px 16px; background: #f1f5f9; border-radius: 40px; font-size: 0.8rem; font-weight: 600; color: #475569; }
        .admin-badge i { color: #667eea; }
        .logout-btn { padding: 8px 24px; background: linear-gradient(135deg, #ef4444, #dc2626); color: white; border-radius: 40px; text-decoration: none; font-size: 0.8rem; font-weight: 600; transition: all 0.3s; }
        .logout-btn:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(239,68,68,0.3); }

        .container { padding: 28px; }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        .stat-card {
            background: white;
            border-radius: 24px;
            padding: 1.5rem;
            transition: all 0.3s;
            border: 1px solid #e2e8f0;
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
            background: linear-gradient(135deg, #667eea, #764ba2);
        }
        .stat-card:hover { transform: translateY(-5px); box-shadow: 0 20px 30px -12px rgba(0,0,0,0.15); }
        .stat-icon { width: 50px; height: 50px; background: linear-gradient(135deg, #eef2ff, #e0e7ff); border-radius: 16px; display: flex; align-items: center; justify-content: center; margin-bottom: 1rem; }
        .stat-icon i { font-size: 24px; color: #667eea; }
        .stat-value { font-size: 32px; font-weight: 800; color: #0f172a; }
        .stat-label { font-size: 13px; color: #64748b; margin-top: 6px; font-weight: 500; }

        /* Welcome Banner */
        .welcome-banner {
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 28px;
            padding: 28px;
            margin-bottom: 28px;
            color: white;
            position: relative;
            overflow: hidden;
        }
        .welcome-banner::before {
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
        .welcome-content { position: relative; z-index: 1; }
        .welcome-banner h1 { font-size: 24px; font-weight: 700; margin-bottom: 8px; }
        .welcome-banner p { opacity: 0.9; }

        /* Filters Bar */
        .filters-bar {
            background: white;
            border-radius: 20px;
            padding: 1rem 1.5rem;
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            border: 1px solid #e2e8f0;
        }
        .search-box {
            display: flex;
            align-items: center;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 40px;
            padding: 0.5rem 1rem;
            gap: 0.5rem;
            flex: 1;
            max-width: 300px;
        }
        .search-box i { color: #94a3b8; }
        .search-box input { border: none; outline: none; font-size: 0.85rem; width: 100%; background: transparent; }
        .filter-tabs { display: flex; gap: 0.5rem; flex-wrap: wrap; }
        .filter-tab {
            padding: 0.5rem 1.2rem;
            border-radius: 30px;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            color: #64748b;
            text-decoration: none;
        }
        .filter-tab:hover, .filter-tab.active { background: linear-gradient(135deg, #667eea, #764ba2); color: white; border-color: transparent; }
        .type-filters { display: flex; gap: 0.5rem; flex-wrap: wrap; }
        .type-chip {
            padding: 0.4rem 1rem;
            border-radius: 30px;
            font-size: 0.75rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            color: #64748b;
            text-decoration: none;
        }
        .type-chip:hover, .type-chip.active { background: #667eea; color: white; border-color: #667eea; }

        /* Alert Messages */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 16px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideIn 0.3s ease;
        }
        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .alert-success { background: #d1fae5; color: #059669; border-left: 4px solid #059669; }
        .alert-error { background: #fee2e2; color: #dc2626; border-left: 4px solid #dc2626; }

        /* Listing Cards */
        .listings-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
            gap: 1.5rem;
        }
        .listing-card {
            background: white;
            border-radius: 24px;
            overflow: hidden;
            transition: all 0.3s;
            border: 1px solid #e2e8f0;
        }
        .listing-card:hover { transform: translateY(-5px); box-shadow: 0 20px 30px -12px rgba(0,0,0,0.15); }
        
        .card-image {
            height: 200px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 48px;
            color: white;
            position: relative;
        }
        .card-image img { width: 100%; height: 100%; object-fit: cover; }
        .card-badge {
            position: absolute;
            top: 12px;
            right: 12px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        .card-badge.rental { background: #3b82f6; }
        .card-badge.product { background: #10b981; }
        .card-badge.job { background: #f59e0b; }
        
        .card-content { padding: 1.25rem; }
        .card-title { font-size: 1.1rem; font-weight: 700; color: #0f172a; margin-bottom: 8px; }
        .card-price { font-size: 1.3rem; font-weight: 800; color: #667eea; margin-bottom: 12px; }
        .card-details { display: flex; gap: 16px; margin-bottom: 12px; font-size: 12px; color: #64748b; flex-wrap: wrap; }
        .card-description { font-size: 13px; color: #475569; line-height: 1.5; margin-bottom: 16px; max-height: 60px; overflow: hidden; }
        
        .seller-info {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 0;
            border-top: 1px solid #e2e8f0;
            border-bottom: 1px solid #e2e8f0;
            margin-bottom: 16px;
        }
        .seller-avatar {
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
        .seller-name { font-weight: 600; font-size: 13px; color: #0f172a; }
        .seller-email { font-size: 11px; color: #64748b; }
        
        /* Action Buttons */
        .action-buttons { display: flex; gap: 12px; }
        .btn-approve, .btn-reject, .btn-view {
            flex: 1;
            padding: 10px;
            border-radius: 40px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            border: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .btn-approve { background: #10b981; color: white; }
        .btn-approve:hover { background: #059669; transform: translateY(-2px); }
        .btn-reject { background: #ef4444; color: white; }
        .btn-reject:hover { background: #dc2626; transform: translateY(-2px); }
        .btn-view { background: #f8fafc; border: 1px solid #e2e8f0; color: #64748b; }
        .btn-view:hover { border-color: #667eea; color: #667eea; }

        /* Approve Modal with Commission & Deposit Fields */
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
            z-index: 1100;
        }
        .modal-content {
            background: white;
            border-radius: 28px;
            padding: 32px;
            width: 550px;
            max-width: 90%;
            animation: modalIn 0.3s ease;
        }
        @keyframes modalIn {
            from { opacity: 0; transform: scale(0.95); }
            to { opacity: 1; transform: scale(1); }
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }
        .modal-header h3 { font-size: 1.3rem; font-weight: 700; color: #0f172a; }
        .close-modal { cursor: pointer; font-size: 28px; color: #94a3b8; transition: color 0.3s; }
        .close-modal:hover { color: #ef4444; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 600; color: #334155; }
        .form-group input, .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            font-family: inherit;
            transition: all 0.3s;
        }
        .form-group input:focus, .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
        }
        .form-group textarea { resize: vertical; min-height: 100px; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        .modal-buttons { display: flex; gap: 12px; margin-top: 24px; }
        .modal-buttons button { flex: 1; padding: 12px; border-radius: 40px; font-weight: 600; cursor: pointer; border: none; transition: all 0.3s; }
        .required-star { color: #ef4444; margin-left: 4px; }
        .info-text { font-size: 11px; color: #64748b; margin-top: 4px; }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px;
            background: white;
            border-radius: 24px;
            border: 1px solid #e2e8f0;
        }
        .empty-state i { font-size: 64px; color: #cbd5e1; margin-bottom: 16px; display: block; }
        .empty-state h3 { font-size: 18px; color: #334155; margin-bottom: 8px; }

        /* Responsive */
        @media (max-width: 1024px) {
            .sidebar { width: 88px; }
            .sidebar .logo-text, .sidebar .menu-label, .sidebar .profile-info, .sidebar .section-header { display: none; }
            .sidebar .menu-item { justify-content: center; padding: 12px; }
            .sidebar .menu-item i { margin-right: 0; font-size: 1.4rem; }
            .main-content { margin-left: 88px; }
            .listings-grid { grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }

        @media (max-width: 768px) {
            .mobile-menu-toggle { display: flex; align-items: center; justify-content: center; }
            .sidebar { transform: translateX(-100%); width: 280px; }
            .sidebar.mobile-open { transform: translateX(0); }
            .sidebar.mobile-open .logo-text, .sidebar.mobile-open .menu-label, .sidebar.mobile-open .profile-info, .sidebar.mobile-open .section-header { display: block; }
            .sidebar.mobile-open .menu-item { justify-content: flex-start; padding: 12px 16px; }
            .sidebar.mobile-open .menu-item i { margin-right: 12px; }
            .main-content { margin-left: 0; }
            .top-bar { padding: 1rem; flex-wrap: wrap; }
            .container { padding: 1rem; }
            .stats-grid { grid-template-columns: 1fr; }
            .listings-grid { grid-template-columns: 1fr; }
            .filters-bar { flex-direction: column; align-items: stretch; }
            .search-box { max-width: 100%; }
            .filter-tabs, .type-filters { justify-content: center; }
            .action-buttons { flex-direction: column; }
            .form-row { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

    <button class="mobile-menu-toggle" id="mobileMenuToggle">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <div class="logo-icon">🏪</div>
                <span class="logo-text">Brokerplace</span>
            </div>
            <button class="collapse-btn" id="collapseBtn">
                <i class="fas fa-chevron-left"></i>
            </button>
        </div>

        <ul class="nav-menu">
            <div class="section-header">Main</div>
            <a href="dashboard.php" class="menu-item">
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
            <a href="approve_listings.php" class="menu-item active">
                <i class="fas fa-check-double"></i>
                <span class="menu-label">Approve Listings</span>
                <?php if ($stats['pending'] > 0): ?>
                    <span class="badge-count"><?php echo $stats['pending']; ?></span>
                <?php endif; ?>
            </a>
            <a href="negotiations.php" class="menu-item">
                <i class="fas fa-handshake"></i>
                <span class="menu-label">Negotiations</span>
            </a>
            <a href="disputes.php" class="menu-item">
                <i class="fas fa-gavel"></i>
                <span class="menu-label">Disputes</span>
            </a>
            <a href="withdrawals.php" class="menu-item">
                <i class="fas fa-money-bill-wave"></i>
                <span class="menu-label">Withdrawals</span>
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

    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <div class="top-bar">
            <h1 class="page-title"><i class="fas fa-check-double"></i> Approve Listings</h1>
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
            
            <!-- Welcome Banner -->
            <div class="welcome-banner">
                <div class="welcome-content">
                    <h1>Listing Management</h1>
                    <p>Review and manage all listing submissions from sellers</p>
                </div>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <div><?php echo $message; ?></div>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <div><?php echo $error; ?></div>
                </div>
            <?php endif; ?>

            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-clock"></i></div>
                    <div class="stat-value"><?php echo $stats['pending']; ?></div>
                    <div class="stat-label">Pending Approval</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                    <div class="stat-value"><?php echo $stats['approved']; ?></div>
                    <div class="stat-label">Approved & Active</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-times-circle"></i></div>
                    <div class="stat-value"><?php echo $stats['rejected']; ?></div>
                    <div class="stat-label">Rejected</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-chart-simple"></i></div>
                    <div class="stat-value"><?php echo $stats['total']; ?></div>
                    <div class="stat-label">Total Listings</div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters-bar">
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <form method="GET" style="flex: 1; display: flex;">
                        <input type="text" name="search" placeholder="Search by title, description, or seller..." value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                        <input type="hidden" name="filter" value="<?php echo $filter; ?>">
                        <input type="hidden" name="type" value="<?php echo $type_filter; ?>">
                    </form>
                </div>
                <div class="filter-tabs">
                    <a href="?filter=pending&type=<?php echo $type_filter; ?>&search=<?php echo urlencode($search); ?>" class="filter-tab <?php echo $filter == 'pending' ? 'active' : ''; ?>">
                        <i class="fas fa-clock"></i> Pending (<?php echo $stats['pending']; ?>)
                    </a>
                    <a href="?filter=approved&type=<?php echo $type_filter; ?>&search=<?php echo urlencode($search); ?>" class="filter-tab <?php echo $filter == 'approved' ? 'active' : ''; ?>">
                        <i class="fas fa-check-circle"></i> Approved
                    </a>
                    <a href="?filter=rejected&type=<?php echo $type_filter; ?>&search=<?php echo urlencode($search); ?>" class="filter-tab <?php echo $filter == 'rejected' ? 'active' : ''; ?>">
                        <i class="fas fa-times-circle"></i> Rejected
                    </a>
                    <a href="?filter=all&type=<?php echo $type_filter; ?>&search=<?php echo urlencode($search); ?>" class="filter-tab <?php echo $filter == 'all' ? 'active' : ''; ?>">
                        <i class="fas fa-list"></i> All
                    </a>
                </div>
                <div class="type-filters">
                    <a href="?filter=<?php echo $filter; ?>&type=all&search=<?php echo urlencode($search); ?>" class="type-chip <?php echo $type_filter == 'all' || $type_filter == '' ? 'active' : ''; ?>">All Types</a>
                    <a href="?filter=<?php echo $filter; ?>&type=rental&search=<?php echo urlencode($search); ?>" class="type-chip <?php echo $type_filter == 'rental' ? 'active' : ''; ?>">🏠 Houses</a>
                    <a href="?filter=<?php echo $filter; ?>&type=product&search=<?php echo urlencode($search); ?>" class="type-chip <?php echo $type_filter == 'product' ? 'active' : ''; ?>">🚗 Cars</a>
                    <a href="?filter=<?php echo $filter; ?>&type=job&search=<?php echo urlencode($search); ?>" class="type-chip <?php echo $type_filter == 'job' ? 'active' : ''; ?>">💼 Jobs</a>
                </div>
            </div>

            <!-- Listings Grid -->
            <?php if ($listings && $listings->num_rows > 0): ?>
                <div class="listings-grid">
                    <?php while($listing = $listings->fetch_assoc()): 
                        $type_icon = '';
                        $type_class = '';
                        $type_label = '';
                        if ($listing['type'] == 'rental') {
                            $type_icon = '🏠';
                            $type_class = 'rental';
                            $type_label = 'For Rent';
                        } elseif ($listing['type'] == 'product') {
                            $type_icon = '🚗';
                            $type_class = 'product';
                            $type_label = 'For Sale';
                        } else {
                            $type_icon = '💼';
                            $type_class = 'job';
                            $type_label = 'Job';
                        }
                        
                        $additional = $listing['additional_details'] ? json_decode($listing['additional_details'], true) : [];
                        $cover_image = $listing['cover_image'] && file_exists('../uploads/listings/' . $listing['cover_image']) 
                            ? '/broker_system/uploads/listings/' . $listing['cover_image'] : '';
                        
                        $status_text = '';
                        $status_color = '';
                        if ($listing['approval_status'] == 'pending') {
                            $status_text = 'Pending Review';
                            $status_color = '#f59e0b';
                        } elseif ($listing['approval_status'] == 'approved') {
                            $status_text = 'Approved';
                            $status_color = '#10b981';
                        } else {
                            $status_text = 'Rejected';
                            $status_color = '#ef4444';
                        }
                        
                        // Calculate recommended commission based on price
                        $recommended_commission = 5;
                        $price = floatval($listing['price']);
                        if ($price > 2000000) {
                            $recommended_commission = 3;
                        } elseif ($price >= 500000) {
                            $recommended_commission = 5;
                        } else {
                            $recommended_commission = 7;
                        }
                        $recommended_deposit = min($price * 0.25, 50000);
                    ?>
                        <div class="listing-card">
                            <div class="card-image">
                                <?php if ($cover_image): ?>
                                    <img src="<?php echo $cover_image; ?>" alt="<?php echo htmlspecialchars($listing['title']); ?>">
                                <?php else: ?>
                                    <div style="font-size: 48px;"><?php echo $type_icon; ?></div>
                                <?php endif; ?>
                                <span class="card-badge <?php echo $type_class; ?>"><?php echo $type_label; ?></span>
                            </div>
                            <div class="card-content">
                                <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                                    <h3 class="card-title"><?php echo htmlspecialchars($listing['title']); ?></h3>
                                    <span style="background: <?php echo $status_color; ?>; padding: 2px 8px; border-radius: 20px; font-size: 10px; color: white;"><?php echo $status_text; ?></span>
                                </div>
                                <div class="card-price"><?php echo formatMoney($listing['price']); ?>
                                    <?php if ($listing['type'] == 'rental'): ?><small>/night</small><?php endif; ?>
                                    <?php if ($listing['type'] == 'job'): ?><small>/month</small><?php endif; ?>
                                </div>
                                <div class="card-details">
                                    <?php if ($listing['location']): ?>
                                        <span><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($listing['location']); ?></span>
                                    <?php endif; ?>
                                    <span><i class="fas fa-eye"></i> <?php echo number_format($listing['views']); ?> views</span>
                                    <span><i class="fas fa-calendar"></i> <?php echo date('M d, Y', strtotime($listing['created_at'])); ?></span>
                                </div>
                                <div class="card-description">
                                    <?php echo htmlspecialchars(substr($listing['description'], 0, 100)); ?>...
                                </div>
                                
                                <!-- Additional Details -->
                                <?php if (!empty($additional)): ?>
                                <div class="card-details" style="margin-top: 8px;">
                                    <?php if ($listing['type'] == 'rental' && !empty($additional['bedrooms'])): ?>
                                        <span><i class="fas fa-bed"></i> <?php echo $additional['bedrooms']; ?> beds</span>
                                    <?php endif; ?>
                                    <?php if ($listing['type'] == 'product' && !empty($additional['year'])): ?>
                                        <span><i class="fas fa-calendar"></i> <?php echo $additional['year']; ?></span>
                                    <?php endif; ?>
                                    <?php if ($listing['type'] == 'job' && !empty($additional['employment_type'])): ?>
                                        <span><i class="fas fa-clock"></i> <?php echo $additional['employment_type']; ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                                
                                <div class="seller-info">
                                    <div class="seller-avatar"><?php echo strtoupper(substr($listing['seller_name'], 0, 1)); ?></div>
                                    <div>
                                        <div class="seller-name"><?php echo htmlspecialchars($listing['seller_name']); ?></div>
                                        <div class="seller-email"><?php echo htmlspecialchars($listing['seller_email']); ?></div>
                                    </div>
                                </div>
                                
                                <div class="action-buttons">
                                    <?php if ($listing['approval_status'] == 'pending'): ?>
                                        <button onclick="openApproveModal(<?php echo $listing['id']; ?>, '<?php echo addslashes($listing['title']); ?>', <?php echo $price; ?>, <?php echo $recommended_commission; ?>, <?php echo $recommended_deposit; ?>)" class="btn-approve">
                                            <i class="fas fa-check-circle"></i> Approve
                                        </button>
                                        <button onclick="openRejectModal(<?php echo $listing['id']; ?>, '<?php echo addslashes($listing['title']); ?>')" class="btn-reject">
                                            <i class="fas fa-times-circle"></i> Reject
                                        </button>
                                    <?php elseif ($listing['approval_status'] == 'approved'): ?>
                                        <button onclick="viewListing(<?php echo $listing['id']; ?>)" class="btn-view">
                                            <i class="fas fa-eye"></i> View Listing
                                        </button>
                                        <button onclick="openRejectModal(<?php echo $listing['id']; ?>, '<?php echo addslashes($listing['title']); ?>')" class="btn-reject">
                                            <i class="fas fa-ban"></i> Unpublish
                                        </button>
                                    <?php else: ?>
                                        <button onclick="openApproveModal(<?php echo $listing['id']; ?>, '<?php echo addslashes($listing['title']); ?>', <?php echo $price; ?>, <?php echo $recommended_commission; ?>, <?php echo $recommended_deposit; ?>)" class="btn-approve">
                                            <i class="fas fa-check-circle"></i> Approve
                                        </button>
                                        <button onclick="viewListing(<?php echo $listing['id']; ?>)" class="btn-view">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <h3>No listings found</h3>
                    <p>No listings match your current filter criteria.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Approve Modal with Commission & Deposit Fields -->
    <div id="approveModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-check-circle" style="color: #10b981;"></i> Approve Listing</h3>
                <span class="close-modal" onclick="closeModal()">&times;</span>
            </div>
            <form method="POST" id="approveForm">
                <input type="hidden" name="listing_id" id="approve_listing_id">
                <input type="hidden" name="action" value="approve_with_commission">
                
                <div class="form-group">
                    <label>Listing Title</label>
                    <input type="text" id="approve_listing_title" readonly style="background: #f8fafc;">
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Commission (%) <span class="required-star">*</span></label>
                        <input type="number" name="commission_percent" id="commission_percent" step="0.5" min="1" max="20" required>
                        <div class="info-text">Recommended: Based on listing price</div>
                    </div>
                    <div class="form-group">
                        <label>Deposit Amount (ETB) <span class="required-star">*</span></label>
                        <input type="number" name="deposit_amount" id="deposit_amount" step="100" min="0" required>
                        <div class="info-text">Minimum deposit to publish listing</div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Admin Notes (Optional)</label>
                    <textarea name="admin_notes" rows="3" placeholder="Add any notes about this approval..."></textarea>
                </div>
                
                <div class="modal-buttons">
                    <button type="submit" style="background: #10b981; color: white;">Approve Listing</button>
                    <button type="button" onclick="closeModal()" style="background: #f1f5f9; color: #64748b;">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Reject Modal -->
    <div id="rejectModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-times-circle" style="color: #ef4444;"></i> Reject Listing</h3>
                <span class="close-modal" onclick="closeModal()">&times;</span>
            </div>
            <form method="POST">
                <input type="hidden" name="listing_id" id="reject_listing_id">
                <input type="hidden" name="action" value="reject">
                <div class="form-group">
                    <label>Listing Title</label>
                    <input type="text" id="reject_listing_title" readonly style="background: #f8fafc;">
                </div>
                <div class="form-group">
                    <label>Reason for Rejection <span style="color: red;">*</span></label>
                    <textarea name="admin_notes" id="reject_reason" required placeholder="Please provide a reason for rejection..."></textarea>
                </div>
                <div class="modal-buttons">
                    <button type="submit" style="background: #ef4444; color: white;">Yes, Reject Listing</button>
                    <button type="button" onclick="closeModal()" style="background: #f1f5f9; color: #64748b;">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Sidebar functionality
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

        if (localStorage.getItem('sidebarCollapsed') === 'true') {
            sidebar.classList.add('collapsed');
            mainContent.classList.add('expanded');
            if (collapseBtn) {
                const icon = collapseBtn.querySelector('i');
                icon.classList.remove('fa-chevron-left');
                icon.classList.add('fa-chevron-right');
            }
        }

        if (mobileMenuToggle) {
            mobileMenuToggle.addEventListener('click', () => {
                sidebar.classList.toggle('mobile-open');
            });
        }

        document.addEventListener('click', (e) => {
            if (window.innerWidth <= 768) {
                if (!sidebar.contains(e.target) && !mobileMenuToggle.contains(e.target)) {
                    sidebar.classList.remove('mobile-open');
                }
            }
        });

        // Modal functions
        function openApproveModal(listingId, listingTitle, price, recommendedCommission, recommendedDeposit) {
            document.getElementById('approve_listing_id').value = listingId;
            document.getElementById('approve_listing_title').value = listingTitle;
            document.getElementById('commission_percent').value = recommendedCommission;
            document.getElementById('deposit_amount').value = Math.round(recommendedDeposit);
            document.getElementById('approveModal').style.display = 'flex';
        }

        function openRejectModal(listingId, listingTitle) {
            document.getElementById('reject_listing_id').value = listingId;
            document.getElementById('reject_listing_title').value = listingTitle;
            document.getElementById('rejectModal').style.display = 'flex';
        }

        function closeModal() {
            document.getElementById('approveModal').style.display = 'none';
            document.getElementById('rejectModal').style.display = 'none';
        }

        function viewListing(listingId) {
            window.open('/broker_system/user/product.php?id=' + listingId, '_blank');
        }

        // Form validation for approve modal
        document.getElementById('approveForm')?.addEventListener('submit', function(e) {
            const commission = document.getElementById('commission_percent').value;
            const deposit = document.getElementById('deposit_amount').value;
            
            if (!commission || commission <= 0) {
                e.preventDefault();
                alert('Please enter a valid commission percentage (greater than 0)');
                return false;
            }
            
            if (!deposit || deposit <= 0) {
                e.preventDefault();
                alert('Please enter a valid deposit amount (greater than 0)');
                return false;
            }
        });

        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }
    </script>
</body>
</html>