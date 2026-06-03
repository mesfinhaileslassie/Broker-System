<?php
// admin/negotiations.php - Complete Negotiations Management System
// FIXED: No session conflicts, proper proposal workflow

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check admin login - Direct check without including auth.php
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: /broker_system/admin/login.php');
    exit;
}

require_once '../config/database.php';
require_once '../includes/functions.php';

$conn = getDbConnection();
$admin_name = $_SESSION['admin_name'] ?? 'Admin';
$message = '';
$error = '';

// Handle negotiation actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $negotiation_id = intval($_POST['negotiation_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    
    if ($action === 'send_proposal') {
        $commission = floatval($_POST['commission_percent'] ?? 0);
        $deposit = floatval($_POST['deposit_amount'] ?? 0);
        $admin_notes = $conn->real_escape_string($_POST['admin_notes'] ?? '');
        
        if ($commission <= 0 || $deposit <= 0) {
            $error = "Commission and deposit amounts are required";
        } else {
            $conn->query("
                UPDATE listing_negotiations 
                SET proposed_commission = $commission,
                    proposed_deposit = $deposit,
                    admin_notes = '$admin_notes',
                    status = 'proposal_sent',
                    sent_at = NOW(),
                    updated_at = NOW()
                WHERE id = $negotiation_id
            ");
            
            $neg = $conn->query("SELECT seller_id, listing_id FROM listing_negotiations WHERE id = $negotiation_id")->fetch_assoc();
            $listing = $conn->query("SELECT title, type FROM listings WHERE id = {$neg['listing_id']}")->fetch_assoc();
            
            $user_type = ($listing['type'] == 'job') ? 'Employer' : 'Seller';
            
            $conn->query("
                INSERT INTO notifications (user_id, title, message, link, created_at) 
                VALUES ({$neg['seller_id']}, '📋 New Commission Proposal', 
                'A new proposal has been sent for your listing \"{$listing['title']}\". Proposed commission: {$commission}% and deposit: " . formatMoney($deposit) . ". Please login to review.', 
                '/broker_system/user/negotiations.php', NOW())
            ");
            
            $message = "Proposal sent to $user_type successfully!";
        }
        
    } elseif ($action === 'update_proposal') {
        $commission = floatval($_POST['commission_percent'] ?? 0);
        $deposit = floatval($_POST['deposit_amount'] ?? 0);
        $admin_notes = $conn->real_escape_string($_POST['admin_notes'] ?? '');
        
        if ($commission <= 0 || $deposit <= 0) {
            $error = "Commission and deposit amounts are required";
        } else {
            $conn->query("
                UPDATE listing_negotiations 
                SET proposed_commission = $commission,
                    proposed_deposit = $deposit,
                    admin_notes = '$admin_notes',
                    status = 'proposal_sent',
                    updated_at = NOW()
                WHERE id = $negotiation_id
            ");
            
            $neg = $conn->query("SELECT seller_id, listing_id FROM listing_negotiations WHERE id = $negotiation_id")->fetch_assoc();
            $listing = $conn->query("SELECT title FROM listings WHERE id = {$neg['listing_id']}")->fetch_assoc();
            
            $conn->query("
                INSERT INTO notifications (user_id, title, message, link, created_at) 
                VALUES ({$neg['seller_id']}, '📋 Updated Proposal', 
                'The commission proposal for \"{$listing['title']}\" has been updated to {$commission}% commission and " . formatMoney($deposit) . " deposit. Please review.', 
                '/broker_system/user/negotiations.php', NOW())
            ");
            
            $message = "Proposal updated and sent successfully!";
        }
    }
}

// Get filter parameter
$filter = $_GET['filter'] ?? 'all';
$search = isset($_GET['search']) ? $conn->real_escape_string(trim($_GET['search'])) : '';

// Build query
$where = "1=1";
if ($filter === 'pending') {
    $where = "ln.status = 'under_review'";
} elseif ($filter === 'proposal_sent') {
    $where = "ln.status = 'proposal_sent'";
} elseif ($filter === 'accepted') {
    $where = "ln.status = 'accepted'";
} elseif ($filter === 'rejected') {
    $where = "ln.status = 'rejected'";
}

if ($search) {
    $where .= " AND (l.title LIKE '%$search%' OR u.full_name LIKE '%$search%' OR u.email LIKE '%$search%')";
}

// Get negotiations
$negotiations = $conn->query("
    SELECT ln.*, l.title, l.type, l.price, l.id as listing_id,
           u.full_name as seller_name, u.email as seller_email, u.id as seller_id,
           (SELECT COUNT(*) FROM negotiation_messages WHERE negotiation_id = ln.id AND is_read = 0 AND sender_type = 'seller') as unread_count
    FROM listing_negotiations ln
    JOIN listings l ON ln.listing_id = l.id
    JOIN users u ON ln.seller_id = u.id
    WHERE $where
    ORDER BY 
        CASE 
            WHEN ln.status = 'proposal_sent' THEN 1
            WHEN ln.status = 'under_review' THEN 2
            ELSE 3
        END,
        ln.created_at DESC
");

// Get statistics
$stats = [
    'total' => $conn->query("SELECT COUNT(*) as count FROM listing_negotiations")->fetch_assoc()['count'] ?? 0,
    'pending' => $conn->query("SELECT COUNT(*) as count FROM listing_negotiations WHERE status = 'under_review'")->fetch_assoc()['count'] ?? 0,
    'proposal_sent' => $conn->query("SELECT COUNT(*) as count FROM listing_negotiations WHERE status = 'proposal_sent'")->fetch_assoc()['count'] ?? 0,
    'accepted' => $conn->query("SELECT COUNT(*) as count FROM listing_negotiations WHERE status = 'accepted'")->fetch_assoc()['count'] ?? 0,
    'rejected' => $conn->query("SELECT COUNT(*) as count FROM listing_negotiations WHERE status = 'rejected'")->fetch_assoc()['count'] ?? 0,
];

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Negotiations Management - Admin Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f5f7fb; }

        /* Sidebar */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 280px;
            height: 100%;
            background: linear-gradient(180deg, #0f172a 0%, #1e293b 100%);
            transition: all 0.3s ease;
            z-index: 1050;
            overflow-y: auto;
            box-shadow: 4px 0 25px rgba(0,0,0,0.1);
        }
        .sidebar-header { padding: 24px 20px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid rgba(255,255,255,0.08); background: #0f172a; }
        .logo { display: flex; align-items: center; gap: 12px; }
        .logo-icon { width: 40px; height: 40px; background: linear-gradient(135deg, #667eea, #764ba2); border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 20px; }
        .logo-text { font-size: 1.2rem; font-weight: 800; color: white; }
        .collapse-btn { background: rgba(255,255,255,0.08); border: none; color: #cbd5e1; width: 32px; height: 32px; border-radius: 10px; cursor: pointer; }
        .collapse-btn:hover { background: rgba(255,255,255,0.18); color: white; }

        .nav-menu { list-style: none; padding: 20px 16px; }
        .section-header { padding: 12px 16px 8px; color: #5b6e8c; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 1.5px; font-weight: 700; }
        .menu-item { display: flex; align-items: center; padding: 12px 16px; border-radius: 12px; color: #94a3b8; transition: all 0.3s; text-decoration: none; gap: 12px; }
        .menu-item i { width: 22px; font-size: 1.1rem; }
        .menu-item span { font-size: 0.85rem; font-weight: 500; }
        .menu-item:hover { background: rgba(255,255,255,0.08); color: white; transform: translateX(5px); }
        .menu-item.active { background: linear-gradient(115deg, #667eea, #764ba2); color: white; }
        .badge-count { background: #ef4444; color: white; font-size: 10px; padding: 2px 8px; border-radius: 20px; margin-left: auto; }

        .sidebar-footer { position: sticky; bottom: 0; padding: 20px 16px; border-top: 1px solid rgba(255,255,255,0.08); background: #0f172a; margin-top: 20px; }
        .profile-item { display: flex; align-items: center; gap: 12px; padding: 10px 12px; border-radius: 12px; text-decoration: none; color: #e2e8f0; }
        .profile-item:hover { background: rgba(255,255,255,0.08); }
        .profile-avatar { width: 42px; height: 42px; background: linear-gradient(135deg, #667eea, #764ba2); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; }
        .profile-name { font-size: 0.85rem; font-weight: 600; }
        .profile-email { font-size: 0.7rem; color: #94a3b8; }

        .mobile-menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1060; background: #667eea; color: white; width: 45px; height: 45px; border-radius: 12px; border: none; cursor: pointer; }

        /* Main Content */
        .main-content { margin-left: 280px; transition: all 0.3s ease; min-height: 100vh; }
        .main-content.expanded { margin-left: 88px; }

        .top-bar { background: rgba(255,255,255,0.95); backdrop-filter: blur(10px); padding: 1rem 2rem; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; border-bottom: 1px solid rgba(0,0,0,0.05); }
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
            grid-template-columns: repeat(5, 1fr);
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        .stat-card {
            background: white;
            border-radius: 20px;
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
        .stat-value { font-size: 28px; font-weight: 800; color: #0f172a; }
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

        /* Alert Messages */
        .alert { padding: 1rem 1.5rem; border-radius: 16px; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 12px; animation: slideIn 0.3s ease; }
        @keyframes slideIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
        .alert-success { background: #d1fae5; color: #059669; border-left: 4px solid #059669; }
        .alert-error { background: #fee2e2; color: #dc2626; border-left: 4px solid #dc2626; }

        /* Negotiation Cards */
        .negotiations-list { display: flex; flex-direction: column; gap: 1.5rem; }
        .negotiation-card {
            background: white;
            border-radius: 24px;
            overflow: hidden;
            transition: all 0.3s;
            border: 1px solid #e2e8f0;
        }
        .negotiation-card:hover { transform: translateY(-3px); box-shadow: 0 20px 30px -12px rgba(0,0,0,0.15); }
        
        .card-header {
            padding: 1.25rem 1.5rem;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .listing-info h3 { font-size: 1rem; font-weight: 700; color: #0f172a; margin-bottom: 4px; }
        .listing-meta { display: flex; gap: 1rem; font-size: 0.7rem; color: #64748b; flex-wrap: wrap; }
        .listing-price { font-size: 1.25rem; font-weight: 800; color: #667eea; }
        
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        .badge-under_review { background: #fef3c7; color: #92400e; }
        .badge-proposal_sent { background: #dbeafe; color: #1e40af; }
        .badge-accepted { background: #d1fae5; color: #059669; }
        .badge-rejected { background: #fee2e2; color: #dc2626; }
        
        .seller-info {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 0.75rem 1.5rem;
            background: #fafcff;
            border-bottom: 1px solid #e2e8f0;
        }
        .seller-avatar {
            width: 36px;
            height: 36px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 14px;
        }
        .seller-name { font-weight: 600; font-size: 13px; color: #0f172a; }
        .seller-email { font-size: 11px; color: #64748b; }
        
        .offer-box {
            padding: 1.25rem 1.5rem;
            background: #fafcff;
            border-bottom: 1px solid #e2e8f0;
        }
        .offer-grid {
            display: flex;
            gap: 2rem;
            flex-wrap: wrap;
            margin-bottom: 1rem;
        }
        .offer-item {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .offer-label { font-size: 0.7rem; font-weight: 500; color: #64748b; text-transform: uppercase; }
        .offer-value { font-size: 1.1rem; font-weight: 700; }
        .offer-value.proposed { color: #667eea; }
        
        .action-buttons {
            padding: 1rem 1.5rem;
            background: white;
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            border-top: 1px solid #e2e8f0;
        }
        .btn {
            padding: 0.5rem 1.25rem;
            border-radius: 30px;
            font-weight: 600;
            font-size: 0.8rem;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }
        .btn-primary { background: linear-gradient(135deg, #667eea, #764ba2); color: white; }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(102,126,234,0.3); }
        .btn-success { background: #10b981; color: white; }
        .btn-success:hover { transform: translateY(-2px); }
        .btn-danger { background: #ef4444; color: white; }
        .btn-danger:hover { transform: translateY(-2px); }
        .btn-outline { background: transparent; border: 1px solid #e2e8f0; color: #64748b; }
        .btn-outline:hover { border-color: #667eea; color: #667eea; transform: translateY(-2px); }

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
            z-index: 1100;
        }
        .modal-content {
            background: white;
            border-radius: 28px;
            padding: 32px;
            width: 500px;
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

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px;
            background: white;
            border-radius: 24px;
            border: 1px solid #e2e8f0;
        }
        .empty-state i { font-size: 64px; color: #cbd5e1; margin-bottom: 16px; display: block; }

        @media (max-width: 1024px) {
            .sidebar { width: 88px; }
            .sidebar .logo-text, .sidebar .menu-label, .sidebar .profile-info, .sidebar .section-header { display: none; }
            .sidebar .menu-item { justify-content: center; padding: 12px; }
            .sidebar .menu-item i { margin-right: 0; font-size: 1.4rem; }
            .main-content { margin-left: 88px; }
            .stats-grid { grid-template-columns: repeat(3, 1fr); }
        }

        @media (max-width: 768px) {
            .mobile-menu-toggle { display: flex; align-items: center; justify-content: center; }
            .sidebar { transform: translateX(-100%); width: 280px; }
            .sidebar.mobile-open { transform: translateX(0); }
            .main-content { margin-left: 0; }
            .top-bar { padding: 1rem; flex-wrap: wrap; }
            .container { padding: 1rem; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .filters-bar { flex-direction: column; align-items: stretch; }
            .search-box { max-width: 100%; }
            .filter-tabs { justify-content: center; }
            .offer-grid { flex-direction: column; gap: 0.75rem; }
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
                <span class="logo-text">Brokerplace Admin</span>
            </div>
            <button class="collapse-btn" id="collapseBtn">
                <i class="fas fa-chevron-left"></i>
            </button>
        </div>

        <ul class="nav-menu">
            <div class="section-header">Main</div>
            <a href="dashboard.php" class="menu-item">
                <i class="fas fa-chart-line"></i> Dashboard
            </a>
            <a href="users.php" class="menu-item">
                <i class="fas fa-users"></i> Users
            </a>
            <a href="transactions.php" class="menu-item">
                <i class="fas fa-exchange-alt"></i> Transactions
            </a>
            
            <div class="section-header">Management</div>
            <a href="approve_listings.php" class="menu-item">
                <i class="fas fa-check-double"></i> Approve Listings
            </a>
            <a href="negotiations.php" class="menu-item active">
                <i class="fas fa-handshake"></i> Negotiations
                <?php if (($stats['pending'] + $stats['proposal_sent']) > 0): ?>
                    <span class="badge-count"><?php echo $stats['pending'] + $stats['proposal_sent']; ?></span>
                <?php endif; ?>
            </a>
            <a href="disputes.php" class="menu-item">
                <i class="fas fa-gavel"></i> Disputes
            </a>
            <a href="withdrawals.php" class="menu-item">
                <i class="fas fa-money-bill-wave"></i> Withdrawals
            </a>
        </ul>

        <div class="sidebar-footer">
            <div class="profile-item">
                <div class="profile-avatar"><?php echo strtoupper(substr($admin_name, 0, 1)); ?></div>
                <div>
                    <div class="profile-name"><?php echo htmlspecialchars($admin_name); ?></div>
                    <div class="profile-email">Administrator</div>
                </div>
            </div>
            <a href="../admin/login.php" class="menu-item" style="margin-top: 15px;">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <div class="top-bar">
            <h1 class="page-title"><i class="fas fa-handshake"></i> Negotiations Management</h1>
            <div class="admin-info">
                <div class="admin-badge">
                    <i class="fas fa-user-shield"></i>
                    <span>Super Admin</span>
                </div>
                <a href="../admin/login.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i> Exit
                </a>
            </div>
        </div>

        <div class="container">
            
            <!-- Welcome Banner -->
            <div class="welcome-banner">
                <div class="welcome-content">
                    <h1>Commission Negotiations</h1>
                    <p>Set commission and deposit amounts for listings. Sellers will review and accept proposals.</p>
                </div>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-clock"></i></div>
                    <div class="stat-value"><?php echo $stats['pending']; ?></div>
                    <div class="stat-label">Pending Review</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-paper-plane"></i></div>
                    <div class="stat-value"><?php echo $stats['proposal_sent']; ?></div>
                    <div class="stat-label">Proposal Sent</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                    <div class="stat-value"><?php echo $stats['accepted']; ?></div>
                    <div class="stat-label">Accepted</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-times-circle"></i></div>
                    <div class="stat-value"><?php echo $stats['rejected']; ?></div>
                    <div class="stat-label">Rejected</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-chart-simple"></i></div>
                    <div class="stat-value"><?php echo $stats['total']; ?></div>
                    <div class="stat-label">Total Negotiations</div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters-bar">
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <form method="GET" style="flex: 1; display: flex;">
                        <input type="text" name="search" placeholder="Search by listing or seller..." value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                        <input type="hidden" name="filter" value="<?php echo $filter; ?>">
                    </form>
                </div>
                <div class="filter-tabs">
                    <a href="?filter=all&search=<?php echo urlencode($search); ?>" class="filter-tab <?php echo $filter == 'all' ? 'active' : ''; ?>">All</a>
                    <a href="?filter=pending&search=<?php echo urlencode($search); ?>" class="filter-tab <?php echo $filter == 'pending' ? 'active' : ''; ?>">Pending Review</a>
                    <a href="?filter=proposal_sent&search=<?php echo urlencode($search); ?>" class="filter-tab <?php echo $filter == 'proposal_sent' ? 'active' : ''; ?>">Proposal Sent</a>
                    <a href="?filter=accepted&search=<?php echo urlencode($search); ?>" class="filter-tab <?php echo $filter == 'accepted' ? 'active' : ''; ?>">Accepted</a>
                    <a href="?filter=rejected&search=<?php echo urlencode($search); ?>" class="filter-tab <?php echo $filter == 'rejected' ? 'active' : ''; ?>">Rejected</a>
                </div>
            </div>

            <!-- Negotiations List -->
            <?php if ($negotiations && $negotiations->num_rows > 0): ?>
                <div class="negotiations-list">
                    <?php while($neg = $negotiations->fetch_assoc()): 
                        $status_class = '';
                        $status_text = '';
                        $status_icon = '';
                        switch($neg['status']) {
                            case 'under_review':
                                $status_class = 'badge-under_review';
                                $status_text = 'Pending Review';
                                $status_icon = 'fa-clock';
                                break;
                            case 'proposal_sent':
                                $status_class = 'badge-proposal_sent';
                                $status_text = 'Proposal Sent - Awaiting Seller Response';
                                $status_icon = 'fa-paper-plane';
                                break;
                            case 'accepted':
                                $status_class = 'badge-accepted';
                                $status_text = 'Accepted';
                                $status_icon = 'fa-check-circle';
                                break;
                            case 'rejected':
                                $status_class = 'badge-rejected';
                                $status_text = 'Rejected';
                                $status_icon = 'fa-times-circle';
                                break;
                            default:
                                $status_class = 'badge-under_review';
                                $status_text = ucfirst(str_replace('_', ' ', $neg['status']));
                                $status_icon = 'fa-clock';
                        }
                        
                        $type_icon = '';
                        $type_label = '';
                        if ($neg['type'] == 'rental') {
                            $type_icon = '🏠';
                            $type_label = 'Rental';
                        } elseif ($neg['type'] == 'product') {
                            $type_icon = '🚗';
                            $type_label = 'Product';
                        } else {
                            $type_icon = '💼';
                            $type_label = 'Job';
                        }
                    ?>
                        <div class="negotiation-card">
                            <div class="card-header">
                                <div class="listing-info">
                                    <h3><?php echo $type_icon; ?> <?php echo htmlspecialchars($neg['title']); ?></h3>
                                    <div class="listing-meta">
                                        <span><i class="fas fa-tag"></i> <?php echo $type_label; ?></span>
                                        <span><i class="fas fa-calendar"></i> <?php echo date('M d, Y', strtotime($neg['created_at'])); ?></span>
                                    </div>
                                </div>
                                <div class="listing-price"><?php echo formatMoney($neg['price']); ?></div>
                            </div>
                            
                            <div class="seller-info">
                                <div class="seller-avatar"><?php echo strtoupper(substr($neg['seller_name'], 0, 1)); ?></div>
                                <div>
                                    <div class="seller-name"><?php echo htmlspecialchars($neg['seller_name']); ?></div>
                                    <div class="seller-email"><?php echo htmlspecialchars($neg['seller_email']); ?></div>
                                </div>
                                <span class="badge <?php echo $status_class; ?>" style="margin-left: auto;">
                                    <i class="fas <?php echo $status_icon; ?>"></i> <?php echo $status_text; ?>
                                </span>
                            </div>
                            
                            <div class="offer-box">
                                <div class="offer-grid">
                                    <div class="offer-item">
                                        <span class="offer-label">Proposed Commission</span>
                                        <span class="offer-value proposed"><?php echo $neg['proposed_commission'] ? $neg['proposed_commission'] . '%' : 'Not set'; ?></span>
                                    </div>
                                    <div class="offer-item">
                                        <span class="offer-label">Proposed Deposit</span>
                                        <span class="offer-value proposed"><?php echo $neg['proposed_deposit'] ? formatMoney($neg['proposed_deposit']) : 'Not set'; ?></span>
                                    </div>
                                </div>
                                
                                <?php if ($neg['admin_notes']): ?>
                                <div class="counter-message" style="background: #dbeafe; border-left-color: #667eea; margin-top: 8px; padding: 8px 12px; border-radius: 8px;">
                                    <i class="fas fa-sticky-note"></i> <strong>Admin Note:</strong> <?php echo htmlspecialchars($neg['admin_notes']); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="action-buttons">
                                <?php if ($neg['status'] == 'under_review'): ?>
                                    <button onclick="openProposeModal(<?php echo $neg['id']; ?>, <?php echo $neg['proposed_commission'] ?: 5; ?>, <?php echo $neg['proposed_deposit'] ?: ($neg['price'] * 0.25); ?>)" class="btn btn-primary">
                                        <i class="fas fa-percent"></i> Send Proposal
                                    </button>
                                    
                                <?php elseif ($neg['status'] == 'proposal_sent'): ?>
                                    <button onclick="openUpdateModal(<?php echo $neg['id']; ?>, <?php echo $neg['proposed_commission']; ?>, <?php echo $neg['proposed_deposit']; ?>)" class="btn btn-primary">
                                        <i class="fas fa-edit"></i> Update Proposal
                                    </button>
                                    <button onclick="openResendModal(<?php echo $neg['id']; ?>, <?php echo $neg['proposed_commission']; ?>, <?php echo $neg['proposed_deposit']; ?>)" class="btn btn-success">
                                        <i class="fas fa-paper-plane"></i> Resend Proposal
                                    </button>
                                    
                                <?php elseif ($neg['status'] == 'rejected'): ?>
                                    <button onclick="openUpdateModal(<?php echo $neg['id']; ?>, <?php echo $neg['proposed_commission']; ?>, <?php echo $neg['proposed_deposit']; ?>)" class="btn btn-primary">
                                        <i class="fas fa-edit"></i> Revise & Resend
                                    </button>
                                <?php endif; ?>
                                
                                <!-- View Listing - Direct link without redirect issues -->
                                <a href="/broker_system/user/product.php?id=<?php echo $neg['listing_id']; ?>" target="_blank" class="btn btn-outline">
                                    <i class="fas fa-eye"></i> View Listing
                                </a>
                                
                                <!-- Message Seller - Direct chat link -->
                                <a href="/broker_system/user/chat.php?user=<?php echo $neg['seller_id']; ?>" target="_blank" class="btn btn-outline">
                                    <i class="fas fa-comment"></i> Message Seller
                                </a>
                            </div>
                        </div>
                    <?php endwhile; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-handshake"></i>
                    <h3>No negotiations found</h3>
                    <p>No negotiations match your current filter criteria.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Send Proposal Modal -->
    <div id="proposeModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-percent"></i> Send Commission Proposal</h3>
                <span class="close-modal" onclick="closeProposeModal()">&times;</span>
            </div>
            <form method="POST">
                <input type="hidden" name="negotiation_id" id="propose_negotiation_id">
                <input type="hidden" name="action" value="send_proposal">
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Commission (%) <span class="required-star">*</span></label>
                        <input type="number" name="commission_percent" id="propose_commission" step="0.5" min="1" max="20" required>
                        <div class="info-text" style="font-size: 11px; color: #64748b; margin-top: 4px;">Recommended: 3-7% based on listing value</div>
                    </div>
                    <div class="form-group">
                        <label>Deposit Amount (ETB) <span class="required-star">*</span></label>
                        <input type="number" name="deposit_amount" id="propose_deposit" step="100" min="0" required>
                        <div class="info-text" style="font-size: 11px; color: #64748b; margin-top: 4px;">Recommended: 25% of listing price (max 50,000 ETB)</div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Admin Notes (Optional)</label>
                    <textarea name="admin_notes" rows="3" placeholder="Add any notes for the seller..."></textarea>
                </div>
                
                <div class="modal-buttons">
                    <button type="submit" class="btn btn-primary">Send Proposal</button>
                    <button type="button" onclick="closeProposeModal()" class="btn btn-outline">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Update Proposal Modal -->
    <div id="updateModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-edit"></i> Update Proposal</h3>
                <span class="close-modal" onclick="closeUpdateModal()">&times;</span>
            </div>
            <form method="POST">
                <input type="hidden" name="negotiation_id" id="update_negotiation_id">
                <input type="hidden" name="action" value="update_proposal">
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Commission (%) <span class="required-star">*</span></label>
                        <input type="number" name="commission_percent" id="update_commission" step="0.5" min="1" max="20" required>
                    </div>
                    <div class="form-group">
                        <label>Deposit Amount (ETB) <span class="required-star">*</span></label>
                        <input type="number" name="deposit_amount" id="update_deposit" step="100" min="0" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Admin Notes (Optional)</label>
                    <textarea name="admin_notes" rows="3" placeholder="Add any notes for the seller..."></textarea>
                </div>
                
                <div class="modal-buttons">
                    <button type="submit" class="btn btn-primary">Update & Resend</button>
                    <button type="button" onclick="closeUpdateModal()" class="btn btn-outline">Cancel</button>
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
        function openProposeModal(id, commission, deposit) {
            document.getElementById('propose_negotiation_id').value = id;
            document.getElementById('propose_commission').value = commission;
            document.getElementById('propose_deposit').value = Math.round(deposit);
            document.getElementById('proposeModal').style.display = 'flex';
        }

        function closeProposeModal() {
            document.getElementById('proposeModal').style.display = 'none';
        }

        function openUpdateModal(id, commission, deposit) {
            document.getElementById('update_negotiation_id').value = id;
            document.getElementById('update_commission').value = commission;
            document.getElementById('update_deposit').value = deposit;
            document.getElementById('updateModal').style.display = 'flex';
        }

        function closeUpdateModal() {
            document.getElementById('updateModal').style.display = 'none';
        }

        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }
    </script>
</body>
</html>