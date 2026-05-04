<?php
// admin/layout.php - Shared layout for all admin pages

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$admin_name = $_SESSION['admin_name'] ?? 'Administrator';
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title ?? 'Admin Panel'; ?> - Ethio Brokerplace</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; overflow-x: hidden; }
        
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 280px;
            height: 100%;
            background: linear-gradient(180deg, #1e293b 0%, #0f172a 100%);
            color: white;
            transition: all 0.3s ease;
            z-index: 1000;
            overflow-y: auto;
        }
        
        .sidebar.collapsed { width: 80px; }
        .sidebar.collapsed .logo-text,
        .sidebar.collapsed .menu-label,
        .sidebar.collapsed .profile-name,
        .sidebar.collapsed .profile-email { display: none; }
        .sidebar.collapsed .menu-item { justify-content: center; padding: 12px; }
        .sidebar.collapsed .menu-item i { margin-right: 0; }
        
        .sidebar-header { padding: 24px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .logo { display: flex; align-items: center; gap: 10px; }
        .logo-icon { font-size: 28px; }
        .logo-text { font-size: 18px; font-weight: 700; }
        .collapse-btn { background: rgba(255,255,255,0.1); border: none; color: white; width: 32px; height: 32px; border-radius: 8px; cursor: pointer; transition: all 0.3s; }
        .collapse-btn:hover { background: rgba(255,255,255,0.2); }
        
        .nav-menu { list-style: none; padding: 20px 16px; }
        .menu-item { display: flex; align-items: center; padding: 12px 16px; margin: 4px 0; border-radius: 12px; color: #cbd5e1; cursor: pointer; transition: all 0.3s; text-decoration: none; }
        .menu-item i { width: 24px; font-size: 18px; margin-right: 12px; }
        .menu-item span { font-size: 14px; font-weight: 500; }
        .menu-item:hover { background: rgba(255,255,255,0.1); color: white; }
        .menu-item.active { background: linear-gradient(135deg, #667eea, #764ba2); color: white; }
        
        .sidebar-footer { position: absolute; bottom: 0; left: 0; right: 0; padding: 20px 16px; border-top: 1px solid rgba(255,255,255,0.1); }
        .profile-item { display: flex; align-items: center; gap: 12px; padding: 12px; border-radius: 12px; cursor: pointer; transition: all 0.3s; text-decoration: none; color: white; }
        .profile-item:hover { background: rgba(255,255,255,0.1); }
        .profile-avatar { width: 40px; height: 40px; background: linear-gradient(135deg, #667eea, #764ba2); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 16px; }
        .profile-info { flex: 1; }
        .profile-name { font-size: 14px; font-weight: 600; }
        .profile-email { font-size: 11px; color: #94a3b8; }
        
        .main-content { margin-left: 280px; transition: all 0.3s ease; min-height: 100vh; }
        .main-content.expanded { margin-left: 80px; }
        
        .top-bar { background: white; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        .page-title { font-size: 24px; font-weight: 700; color: #0f172a; }
        .admin-info { display: flex; align-items: center; gap: 20px; }
        .logout-btn { padding: 8px 20px; background: linear-gradient(135deg, #ef4444, #dc2626); color: white; border-radius: 30px; text-decoration: none; font-weight: 500; transition: all 0.3s; }
        .logout-btn:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(239,68,68,0.3); }
        .container { padding: 24px; }
        
        .card { background: white; border-radius: 20px; padding: 24px; margin-bottom: 24px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 16px; border-bottom: 2px solid #f1f5f9; }
        .card-header h2 { font-size: 18px; font-weight: 600; color: #0f172a; }
        .table-wrapper { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 14px 12px; text-align: left; border-bottom: 1px solid #f1f5f9; font-size: 13px; }
        th { font-weight: 600; color: #64748b; }
        tr:hover { background: #f8fafc; }
        .badge { padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 500; display: inline-block; }
        .badge-success { background: #d1fae5; color: #059669; }
        .badge-warning { background: #fed7aa; color: #ea580c; }
        .badge-danger { background: #fee2e2; color: #dc2626; }
        .badge-info { background: #dbeafe; color: #2563eb; }
        .btn-sm { padding: 6px 12px; font-size: 12px; border-radius: 8px; border: none; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-primary { background: #667eea; color: white; }
        .btn-danger { background: #ef4444; color: white; }
        .btn-success { background: #10b981; color: white; }
        
        @media (max-width: 1024px) {
            .sidebar { width: 80px; }
            .sidebar .logo-text, .sidebar .menu-label, .sidebar .profile-name, .sidebar .profile-email { display: none; }
            .sidebar .menu-item { justify-content: center; padding: 12px; }
            .sidebar .menu-item i { margin-right: 0; }
            .main-content { margin-left: 80px; }
        }
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.mobile-open { transform: translateX(0); width: 280px; }
            .sidebar.mobile-open .logo-text, .sidebar.mobile-open .menu-label, .sidebar.mobile-open .profile-name, .sidebar.mobile-open .profile-email { display: block; }
            .sidebar.mobile-open .menu-item { justify-content: flex-start; }
            .sidebar.mobile-open .menu-item i { margin-right: 12px; }
            .main-content { margin-left: 0; }
            .mobile-menu-btn { display: block; }
        }
    </style>
</head>
<body>
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo"><span class="logo-icon">🏪</span><span class="logo-text">Brokerplace</span></div>
            <button class="collapse-btn" id="collapseBtn"><i class="fas fa-chevron-left"></i></button>
        </div>
        <ul class="nav-menu">
            <a href="dashboard.php" class="menu-item <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>"><i class="fas fa-tachometer-alt"></i><span class="menu-label">Dashboard</span></a>
            <a href="users.php" class="menu-item <?php echo $current_page == 'users.php' ? 'active' : ''; ?>"><i class="fas fa-users"></i><span class="menu-label">Users</span></a>
            <a href="companies.php" class="menu-item <?php echo $current_page == 'companies.php' ? 'active' : ''; ?>"><i class="fas fa-building"></i><span class="menu-label">Companies</span></a>
            <a href="transactions.php" class="menu-item <?php echo $current_page == 'transactions.php' ? 'active' : ''; ?>"><i class="fas fa-exchange-alt"></i><span class="menu-label">Transactions</span></a>
            <a href="approve_listings.php" class="menu-item <?php echo $current_page == 'approve_listings.php' ? 'active' : ''; ?>"><i class="fas fa-check-double"></i><span class="menu-label">Approve Listings</span></a>
            <a href="disputes.php" class="menu-item <?php echo $current_page == 'disputes.php' ? 'active' : ''; ?>"><i class="fas fa-gavel"></i><span class="menu-label">Disputes</span></a>
            <a href="withdrawals.php" class="menu-item <?php echo $current_page == 'withdrawals.php' ? 'active' : ''; ?>"><i class="fas fa-money-bill-wave"></i><span class="menu-label">Withdrawals</span></a>
            <a href="settings.php" class="menu-item <?php echo $current_page == 'settings.php' ? 'active' : ''; ?>"><i class="fas fa-cog"></i><span class="menu-label">Settings</span></a>
        </ul>
        <div class="sidebar-footer">
            <div class="profile-item"><div class="profile-avatar"><?php echo strtoupper(substr($admin_name, 0, 1)); ?></div><div class="profile-info"><div class="profile-name"><?php echo htmlspecialchars($admin_name); ?></div><div class="profile-email">Administrator</div></div></div>
            <a href="logout.php" class="menu-item" style="margin-top: 8px;"><i class="fas fa-sign-out-alt logout-icon"></i><span class="menu-label">Logout</span></a>
        </div>
    </div>
    <div class="main-content" id="mainContent">
        <div class="top-bar">
            <h1 class="page-title"><?php echo $page_title ?? 'Dashboard'; ?></h1>
            <div class="admin-info">
                <span><i class="fas fa-user-shield"></i> <?php echo htmlspecialchars($admin_name); ?></span>
                <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>
        <div class="container">
            <?php echo $content ?? ''; ?>
        </div>
    </div>
    <script>
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const collapseBtn = document.getElementById('collapseBtn');
        if (collapseBtn) {
            collapseBtn.addEventListener('click', () => {
                sidebar.classList.toggle('collapsed');
                mainContent.classList.toggle('expanded');
                const icon = collapseBtn.querySelector('i');
                if (sidebar.classList.contains('collapsed')) { icon.classList.remove('fa-chevron-left'); icon.classList.add('fa-chevron-right'); }
                else { icon.classList.remove('fa-chevron-right'); icon.classList.add('fa-chevron-left'); }
                localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
            });
        }
        if (localStorage.getItem('sidebarCollapsed') === 'true') {
            sidebar.classList.add('collapsed');
            mainContent.classList.add('expanded');
            if (collapseBtn) { const icon = collapseBtn.querySelector('i'); icon.classList.remove('fa-chevron-left'); icon.classList.add('fa-chevron-right'); }
        }
    </script>
</body>
</html>