BRS/admin/admin_functions.php

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

BRS/admin/ajax/get_negotiation_messages.php

<?php
// ============================================
// FILE: broker_system/admin/ajax/get_negotiation_messages.php
// ============================================

require_once '../../config/database.php';
require_once '../../includes/auth.php';

header('Content-Type: application/json');

if (!isLoggedIn() || $_SESSION['user_role'] != 'admin') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$negotiation_id = intval($_GET['id'] ?? 0);
if (!$negotiation_id) {
    echo json_encode(['success' => false, 'error' => 'Invalid negotiation ID']);
    exit;
}

$conn = getDbConnection();

$result = $conn->query("
    SELECT nm.*, 
           CASE 
               WHEN nm.sender_type = 'admin' THEN 'admin'
               WHEN nm.sender_type = 'seller' THEN 'seller'
               ELSE 'system'
           END as sender_type
    FROM negotiation_messages nm
    WHERE nm.negotiation_id = $negotiation_id
    ORDER BY nm.created_at ASC
");

$messages = [];
while ($row = $result->fetch_assoc()) {
    $messages[] = [
        'id' => $row['id'],
        'sender_type' => $row['sender_type'],
        'message' => $row['message'],
        'time' => date('M d, H:i', strtotime($row['created_at']))
    ];
}

$conn->close();
echo json_encode(['success' => true, 'messages' => $messages]);
?>

BRS/admin/ajax/get_user_details.php

<?php
// admin/ajax/get_user_details.php - Get user details for modal

require_once '../../config/database.php';
require_once '../../includes/auth.php';

header('Content-Type: application/json');

if (!isLoggedIn() || $_SESSION['user_role'] != 'admin') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$user_id = intval($_GET['id'] ?? 0);

if (!$user_id) {
    echo json_encode(['success' => false, 'error' => 'Invalid user ID']);
    exit;
}

$conn = getDbConnection();

$user = $conn->query("
    SELECT u.*, 
           (SELECT COUNT(*) FROM listings WHERE seller_id = u.id) as total_listings,
           (SELECT COUNT(*) FROM transactions WHERE buyer_id = u.id OR seller_id = u.id) as total_transactions
    FROM users u
    WHERE u.id = $user_id
")->fetch_assoc();

$conn->close();

if ($user) {
    echo json_encode(['success' => true, 'user' => $user]);
} else {
    echo json_encode(['success' => false, 'error' => 'User not found']);
}
?>

BRS/admin/analytics.php

<?php
// admin/analytics.php - Analytics Dashboard

$page_title = 'Analytics Dashboard';
ob_start();

require_once '../config/database.php';
require_once '../includes/functions.php';

$conn = getDbConnection();

// Get data for charts
$dailyRevenue = [];
$dailyUsers = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $rev = $conn->query("SELECT SUM(commission_amount) as total FROM transactions WHERE DATE(completed_at) = '$date' AND status = 'completed'")->fetch_assoc()['total'] ?? 0;
    $users = $conn->query("SELECT COUNT(*) as count FROM users WHERE DATE(created_at) = '$date'")->fetch_assoc()['count'];
    $dailyRevenue[] = $rev;
    $dailyUsers[] = $users;
}

// Monthly revenue for last 6 months
$monthlyRevenue = [];
for ($i = 5; $i >= 0; $i--) {
    $month = date('Y-m', strtotime("-$i months"));
    $rev = $conn->query("SELECT SUM(commission_amount) as total FROM transactions WHERE DATE_FORMAT(created_at, '%Y-%m') = '$month' AND status = 'completed'")->fetch_assoc()['total'] ?? 0;
    $monthlyRevenue[] = $rev;
}
$months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
$currentMonth = date('n') - 1;
$last6Months = [];
for ($i = 5; $i >= 0; $i--) {
    $index = ($currentMonth - $i + 12) % 12;
    $last6Months[] = $months[$index];
}

// Top users by spending
$topBuyers = $conn->query("
    SELECT u.full_name, u.email, SUM(t.total_amount) as total_spent
    FROM transactions t
    JOIN users u ON t.buyer_id = u.id
    WHERE t.status = 'completed'
    GROUP BY t.buyer_id
    ORDER BY total_spent DESC
    LIMIT 5
");

// Top sellers by earnings
$topSellers = $conn->query("
    SELECT u.full_name, u.email, SUM(t.total_amount) as total_earned
    FROM transactions t
    JOIN users u ON t.seller_id = u.id
    WHERE t.status = 'completed'
    GROUP BY t.seller_id
    ORDER BY total_earned DESC
    LIMIT 5
");

// Transactions by type
$transactionsByType = [
    'products' => $conn->query("SELECT COUNT(*) as count FROM transactions t JOIN listings l ON t.listing_id = l.id WHERE l.type = 'product' AND t.status = 'completed'")->fetch_assoc()['count'],
    'jobs' => $conn->query("SELECT COUNT(*) as count FROM transactions t JOIN listings l ON t.listing_id = l.id WHERE l.type = 'job' AND t.status = 'completed'")->fetch_assoc()['count'],
    'rentals' => $conn->query("SELECT COUNT(*) as count FROM transactions t JOIN listings l ON t.listing_id = l.id WHERE l.type = 'rental' AND t.status = 'completed'")->fetch_assoc()['count'],
];

$conn->close();
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
    .chart-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 24px; margin-bottom: 24px; }
    .chart-card { background: white; border-radius: 20px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
    .chart-card h3 { margin-bottom: 20px; font-size: 16px; font-weight: 600; }
    canvas { max-height: 250px; width: 100% !important; }
    .stats-number { font-size: 24px; font-weight: 700; color: #667eea; }
    @media (max-width: 768px) { .chart-grid { grid-template-columns: 1fr; } }
</style>

<div class="chart-grid">
    <div class="chart-card">
        <h3><i class="fas fa-chart-line"></i> Daily Revenue (Last 7 Days)</h3>
        <canvas id="revenueChart"></canvas>
    </div>
    <div class="chart-card">
        <h3><i class="fas fa-users"></i> New Users (Last 7 Days)</h3>
        <canvas id="usersChart"></canvas>
    </div>
</div>

<div class="chart-grid">
    <div class="chart-card">
        <h3><i class="fas fa-chart-bar"></i> Monthly Revenue</h3>
        <canvas id="monthlyChart"></canvas>
    </div>
    <div class="chart-card">
        <h3><i class="fas fa-chart-pie"></i> Transactions by Type</h3>
        <canvas id="typeChart"></canvas>
        <div style="margin-top: 16px;">
            <p>📦 Products: <strong><?php echo $transactionsByType['products']; ?></strong></p>
            <p>💼 Jobs: <strong><?php echo $transactionsByType['jobs']; ?></strong></p>
            <p>🏠 Rentals: <strong><?php echo $transactionsByType['rentals']; ?></strong></p>
        </div>
    </div>
</div>

<div class="chart-grid">
    <div class="chart-card">
        <h3><i class="fas fa-trophy"></i> Top Buyers</h3>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>User</th><th>Total Spent</th></tr></thead>
                <tbody>
                    <?php while($row = $topBuyers->fetch_assoc()): ?>
                    <tr><td><?php echo htmlspecialchars($row['full_name']); ?></td><td><?php echo formatMoney($row['total_spent']); ?></td></tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="chart-card">
        <h3><i class="fas fa-award"></i> Top Sellers</h3>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>User</th><th>Total Earned</th></tr></thead>
                <tbody>
                    <?php while($row = $topSellers->fetch_assoc()): ?>
                    <tr><td><?php echo htmlspecialchars($row['full_name']); ?></td><td><?php echo formatMoney($row['total_earned']); ?></td></tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
// Daily Revenue Chart
new Chart(document.getElementById('revenueChart'), {
    type: 'line',
    data: { labels: ['Day 6', 'Day 5', 'Day 4', 'Day 3', 'Day 2', 'Yesterday', 'Today'], datasets: [{ label: 'Revenue (ETB)', data: <?php echo json_encode($dailyRevenue); ?>, borderColor: '#667eea', backgroundColor: 'rgba(102,126,234,0.1)', tension: 0.4, fill: true }] },
    options: { responsive: true, maintainAspectRatio: true }
});

// New Users Chart
new Chart(document.getElementById('usersChart'), {
    type: 'bar',
    data: { labels: ['Day 6', 'Day 5', 'Day 4', 'Day 3', 'Day 2', 'Yesterday', 'Today'], datasets: [{ label: 'New Users', data: <?php echo json_encode($dailyUsers); ?>, backgroundColor: '#10b981', borderRadius: 8 }] },
    options: { responsive: true, maintainAspectRatio: true }
});

// Monthly Revenue Chart
new Chart(document.getElementById('monthlyChart'), {
    type: 'bar',
    data: { labels: <?php echo json_encode($last6Months); ?>, datasets: [{ label: 'Revenue (ETB)', data: <?php echo json_encode($monthlyRevenue); ?>, backgroundColor: '#667eea', borderRadius: 8 }] },
    options: { responsive: true, maintainAspectRatio: true }
});

// Transactions by Type Chart
new Chart(document.getElementById('typeChart'), {
    type: 'doughnut',
    data: { labels: ['Products', 'Jobs', 'Rentals'], datasets: [{ data: [<?php echo $transactionsByType['products']; ?>, <?php echo $transactionsByType['jobs']; ?>, <?php echo $transactionsByType['rentals']; ?>], backgroundColor: ['#667eea', '#10b981', '#f59e0b'] }] },
    options: { responsive: true, maintainAspectRatio: true }
});
</script>

<?php
$content = ob_get_clean();
include 'layout.php';
?>

BRS/admin/approve_companies.php

<?php
// admin/approve_companies.php - Company Approval Management

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/validation.php';

// Check if logged in and is admin
if (!isLoggedIn() || $_SESSION['user_role'] != 'admin') {
    header('Location: /broker_system/auth/login.php');
    exit;
}

$page_title = 'Company Approvals';
ob_start();

$conn = getDbConnection();
$message = '';
$error = '';

// Handle approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $company_id = sanitizeInt($_POST['company_id'] ?? 0);
    $action = sanitizeString($_POST['action'] ?? '');
    
    if ($action === 'approve') {
        $stmt = $conn->prepare("UPDATE companies SET is_approved = 1, approved_at = NOW() WHERE id = ?");
        $stmt->bind_param("i", $company_id);
        if ($stmt->execute()) {
            // Get user_id to update user status
            $user = $conn->query("SELECT user_id FROM companies WHERE id = $company_id")->fetch_assoc();
            if ($user) {
                $conn->query("UPDATE users SET is_verified = 1 WHERE id = {$user['user_id']}");
                
                // Send notification
                $conn->query("INSERT INTO notifications (user_id, title, message, created_at) 
                    VALUES ({$user['user_id']}, 'Company Approved', 'Your company account has been approved! You can now post jobs.', NOW())");
            }
            $message = "Company approved successfully";
        } else {
            $error = "Failed to approve company";
        }
    } elseif ($action === 'reject') {
        $reason = sanitizeString($_POST['reason'] ?? 'No reason provided');
        $user = $conn->query("SELECT user_id FROM companies WHERE id = $company_id")->fetch_assoc();
        
        if ($user) {
            // Send rejection notification
            $conn->query("INSERT INTO notifications (user_id, title, message, created_at) 
                VALUES ({$user['user_id']}, 'Company Rejected', 'Your company registration was rejected. Reason: $reason', NOW())");
            
            // Delete company and user
            $conn->begin_transaction();
            try {
                $conn->query("DELETE FROM companies WHERE id = $company_id");
                $conn->query("DELETE FROM users WHERE id = {$user['user_id']}");
                $conn->commit();
                $message = "Company rejected and removed";
            } catch (Exception $e) {
                $conn->rollback();
                $error = "Failed to reject company";
            }
        }
    }
}

// Get pending companies
$pending_companies = $conn->query("
    SELECT c.*, u.full_name, u.email, u.phone, u.created_at as registered_at
    FROM companies c
    JOIN users u ON c.user_id = u.id
    WHERE c.is_approved = 0
    ORDER BY c.created_at DESC
");

// Get approved companies count
$approved_count = $conn->query("SELECT COUNT(*) as count FROM companies WHERE is_approved = 1")->fetch_assoc()['count'];
$pending_count = $conn->query("SELECT COUNT(*) as count FROM companies WHERE is_approved = 0")->fetch_assoc()['count'];
$total_count = $conn->query("SELECT COUNT(*) as count FROM companies")->fetch_assoc()['count'];

$conn->close();
?>

<style>
    .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 28px; }
    .stat-card { background: white; border-radius: 20px; padding: 24px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
    .stat-value { font-size: 32px; font-weight: 700; color: #0f172a; }
    .stat-label { font-size: 13px; color: #64748b; margin-top: 6px; }
    .card { background: white; border-radius: 20px; padding: 24px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
    .company-card { border: 1px solid #e2e8f0; margin-bottom: 20px; transition: all 0.3s; }
    .company-card:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,0.1); }
    .company-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 12px; }
    .company-name { font-size: 20px; font-weight: 700; color: #0f172a; }
    .company-meta { color: #64748b; font-size: 13px; }
    .info-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; margin: 16px 0; padding: 16px; background: #f8fafc; border-radius: 16px; }
    .info-item { display: flex; flex-direction: column; }
    .info-label { font-size: 11px; color: #64748b; text-transform: uppercase; }
    .info-value { font-size: 14px; font-weight: 600; color: #1e293b; margin-top: 4px; }
    .btn-group { display: flex; gap: 12px; margin-top: 16px; }
    .btn-approve { background: #10b981; color: white; padding: 10px 24px; border: none; border-radius: 40px; cursor: pointer; font-weight: 600; }
    .btn-reject { background: #ef4444; color: white; padding: 10px 24px; border: none; border-radius: 40px; cursor: pointer; font-weight: 600; }
    .btn-approve:hover { background: #059669; transform: translateY(-1px); }
    .btn-reject:hover { background: #dc2626; transform: translateY(-1px); }
    .alert { padding: 12px 16px; border-radius: 12px; margin-bottom: 20px; }
    .alert-success { background: #d1fae5; color: #059669; border-left: 4px solid #059669; }
    .alert-error { background: #fee2e2; color: #dc2626; border-left: 4px solid #dc2626; }
    .empty-state { text-align: center; padding: 60px; }
    .empty-state i { font-size: 64px; color: #cbd5e1; margin-bottom: 16px; display: block; }
    .reject-form { display: none; margin-top: 16px; padding-top: 16px; border-top: 1px solid #e2e8f0; }
    .reject-form textarea { width: 100%; padding: 12px; border: 1px solid #e2e8f0; border-radius: 12px; margin-bottom: 12px; font-family: inherit; }
    @media (max-width: 768px) { .stats-grid { grid-template-columns: 1fr; } .info-grid { grid-template-columns: 1fr; } }
</style>

<div class="page-header">
    <h1><i class="fas fa-building"></i> Company Approvals</h1>
    <p>Review and approve company registrations</p>
</div>

<!-- Statistics -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-value"><?php echo $total_count; ?></div>
        <div class="stat-label">Total Companies</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo $pending_count; ?></div>
        <div class="stat-label">Pending Approval</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo $approved_count; ?></div>
        <div class="stat-label">Approved</div>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $message; ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?></div>
<?php endif; ?>

<?php if ($pending_companies && $pending_companies->num_rows > 0): ?>
    <?php while($company = $pending_companies->fetch_assoc()): ?>
        <div class="card company-card">
            <div class="company-header">
                <div>
                    <div class="company-name"><?php echo htmlspecialchars($company['business_name']); ?></div>
                    <div class="company-meta">Registered: <?php echo date('F d, Y', strtotime($company['registered_at'])); ?></div>
                </div>
                <div class="badge" style="background: #fed7aa; color: #ea580c; padding: 4px 12px; border-radius: 20px;">Pending Review</div>
            </div>
            
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">Contact Person</div>
                    <div class="info-value"><?php echo htmlspecialchars($company['full_name']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Email</div>
                    <div class="info-value"><?php echo htmlspecialchars($company['email']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Phone</div>
                    <div class="info-value"><?php echo htmlspecialchars($company['phone'] ?: 'Not provided'); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Business Type</div>
                    <div class="info-value"><?php echo htmlspecialchars($company['business_type'] ?: 'Not specified'); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">TIN Number</div>
                    <div class="info-value"><?php echo htmlspecialchars($company['tin_number']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Address</div>
                    <div class="info-value"><?php echo htmlspecialchars($company['address'] ?: 'Not provided'); ?></div>
                </div>
            </div>
            
            <div class="btn-group">
                <button class="btn-approve" onclick="approveCompany(<?php echo $company['id']; ?>)">
                    <i class="fas fa-check"></i> Approve Company
                </button>
                <button class="btn-reject" onclick="showRejectForm(<?php echo $company['id']; ?>)">
                    <i class="fas fa-times"></i> Reject
                </button>
            </div>
            
            <div id="rejectForm_<?php echo $company['id']; ?>" class="reject-form">
                <form method="POST" onsubmit="return confirm('Reject this company? This will delete the account.')">
                    <input type="hidden" name="company_id" value="<?php echo $company['id']; ?>">
                    <input type="hidden" name="action" value="reject">
                    <textarea name="reason" rows="3" placeholder="Reason for rejection..." required></textarea>
                    <div style="display: flex; gap: 10px;">
                        <button type="submit" class="btn-reject" style="padding: 8px 20px;">Confirm Rejection</button>
                        <button type="button" onclick="hideRejectForm(<?php echo $company['id']; ?>)" style="padding: 8px 20px; background: #64748b; color: white; border: none; border-radius: 40px; cursor: pointer;">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endwhile; ?>
<?php else: ?>
    <div class="empty-state">
        <i class="fas fa-check-circle" style="color: #10b981;"></i>
        <h3>No Pending Approvals</h3>
        <p>All company registrations have been processed.</p>
        <a href="dashboard.php" class="btn" style="display: inline-block; margin-top: 16px; background: #667eea; color: white; padding: 10px 24px; border-radius: 40px; text-decoration: none;">Back to Dashboard</a>
    </div>
<?php endif; ?>

<script>
    function approveCompany(companyId) {
        if (confirm('Approve this company? They will be able to post jobs immediately.')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="company_id" value="${companyId}">
                <input type="hidden" name="action" value="approve">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    }
    
    function showRejectForm(companyId) {
        document.getElementById(`rejectForm_${companyId}`).style.display = 'block';
    }
    
    function hideRejectForm(companyId) {
        document.getElementById(`rejectForm_${companyId}`).style.display = 'none';
    }
</script>

<?php
$content = ob_get_clean();
include 'layout.php';
?>

BRS/admin/approve_listings.php

<?php
// admin/approve_listings.php - Updated to show all pending listings

$page_title = 'Approve Listings';
ob_start();

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

if (!isLoggedIn() || $_SESSION['user_role'] != 'admin') {
    header('Location: /broker_system/auth/login.php');
    exit;
}

$conn = getDbConnection();
$message = '';
$error = '';

// Handle negotiation actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['propose_terms'])) {
        $listing_id = intval($_POST['listing_id']);
        $commission = floatval($_POST['commission_percent']);
        $deposit = floatval($_POST['deposit_amount']);
        $notes = $conn->real_escape_string($_POST['admin_notes'] ?? '');
        
        // Check if negotiation exists
        $neg_check = $conn->query("SELECT id FROM listing_negotiations WHERE listing_id = $listing_id");
        if ($neg_check->num_rows > 0) {
            $negotiation_id = $neg_check->fetch_assoc()['id'];
            $update = $conn->prepare("
                UPDATE listing_negotiations 
                SET proposed_commission = ?, proposed_deposit = ?, admin_notes = ?, 
                    status = 'commission_proposed', updated_at = NOW()
                WHERE id = ?
            ");
            $update->bind_param("ddsi", $commission, $deposit, $notes, $negotiation_id);
            $update->execute();
        } else {
            $listing = $conn->query("SELECT seller_id FROM listings WHERE id = $listing_id")->fetch_assoc();
            $insert = $conn->prepare("
                INSERT INTO listing_negotiations (listing_id, seller_id, proposed_commission, proposed_deposit, admin_notes, status, created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, 'commission_proposed', NOW(), NOW())
            ");
            $insert->bind_param("iidds", $listing_id, $listing['seller_id'], $commission, $deposit, $notes);
            $insert->execute();
            $negotiation_id = $conn->insert_id;
        }
        
        // Update listing approval status
        $conn->query("UPDATE listings SET approval_status = 'approved' WHERE id = $listing_id");
        
        $listing = $conn->query("SELECT title, seller_id FROM listings WHERE id = $listing_id")->fetch_assoc();
        $notif_stmt = $conn->prepare("
            INSERT INTO notifications (user_id, title, message, created_at) 
            VALUES (?, 'Commission Proposal', 'Admin has proposed {$commission}% commission and " . formatMoney($deposit) . " deposit for your listing \"{$listing['title']}\". Please accept to publish.', NOW())
        ");
        $notif_stmt->bind_param("i", $listing['seller_id']);
        $notif_stmt->execute();
        
        $message = "Terms proposed to seller! They will review and respond.";
    }
    
    if (isset($_POST['accept_counter'])) {
        $negotiation_id = intval($_POST['negotiation_id']);
        $conn->query("
            UPDATE listing_negotiations 
            SET proposed_commission = counter_commission,
                proposed_deposit = counter_deposit,
                counter_commission = NULL,
                counter_deposit = NULL,
                status = 'agreement_accepted',
                accepted_at = NOW()
            WHERE id = $negotiation_id
        ");
        
        $neg = $conn->query("SELECT seller_id, listing_id FROM listing_negotiations WHERE id = $negotiation_id")->fetch_assoc();
        $listing = $conn->query("SELECT title FROM listings WHERE id = {$neg['listing_id']}")->fetch_assoc();
        
        // Update listing to approved
        $conn->query("UPDATE listings SET approval_status = 'approved' WHERE id = {$neg['listing_id']}");
        
        $notif_stmt = $conn->prepare("
            INSERT INTO notifications (user_id, title, message, created_at) 
            VALUES (?, 'Counter Offer Accepted', 'Your counter offer for \"{$listing['title']}\" has been accepted! Please pay the deposit to publish your listing.', NOW())
        ");
        $notif_stmt->bind_param("i", $neg['seller_id']);
        $notif_stmt->execute();
        
        $message = "Counter offer accepted! Waiting for seller payment.";
    }
    
    if (isset($_POST['reject_counter'])) {
        $negotiation_id = intval($_POST['negotiation_id']);
        $conn->query("
            UPDATE listing_negotiations 
            SET counter_commission = NULL,
                counter_deposit = NULL,
                status = 'commission_proposed'
            WHERE id = $negotiation_id
        ");
        $message = "Counter offer rejected. Original proposal remains active.";
    }
}

// Get ALL pending listings (approval_status = 'pending')
$pendingListings = $conn->query("
    SELECT l.*, u.full_name as seller_name, u.email as seller_email,
           ln.id as negotiation_id, ln.status as negotiation_status,
           ln.proposed_commission, ln.proposed_deposit,
           ln.counter_commission, ln.counter_deposit, ln.counter_message
    FROM listings l
    JOIN users u ON l.seller_id = u.id
    LEFT JOIN listing_negotiations ln ON l.id = ln.listing_id
    WHERE l.approval_status = 'pending'
    ORDER BY l.created_at DESC
");

// Also check for listings that might need payment
$paymentNeeded = $conn->query("
    SELECT l.*, u.full_name as seller_name, u.email as seller_email,
           ln.id as negotiation_id, ln.status as negotiation_status,
           ln.proposed_commission, ln.proposed_deposit,
           ln.counter_commission, ln.counter_deposit
    FROM listings l
    JOIN users u ON l.seller_id = u.id
    LEFT JOIN listing_negotiations ln ON l.id = ln.listing_id
    WHERE l.approval_status = 'approved' AND l.status = 'pending'
    ORDER BY l.created_at DESC
");

// Get statistics
$stats = [
    'pending' => $conn->query("SELECT COUNT(*) as count FROM listings WHERE approval_status = 'pending'")->fetch_assoc()['count'],
    'negotiating' => $conn->query("SELECT COUNT(*) as count FROM listing_negotiations WHERE status IN ('commission_proposed', 'counter_offer_sent')")->fetch_assoc()['count'],
    'pending_payment' => $conn->query("SELECT COUNT(*) as count FROM listings WHERE approval_status = 'approved' AND status = 'pending'")->fetch_assoc()['count'],
];

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approve Listings - Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* Your existing styles */
        :root {
            --primary: #4f46e5;
            --primary-dark: #4338ca;
            --primary-light: #eef2ff;
            --secondary: #7c3aed;
            --success: #10b981;
            --success-light: #d1fae5;
            --warning: #f59e0b;
            --warning-light: #fef3c7;
            --danger: #ef4444;
            --danger-light: #fee2e2;
            --info: #3b82f6;
            --info-light: #dbeafe;
            --dark: #1e293b;
            --gray: #64748b;
            --gray-light: #f8fafc;
            --border: #e2e8f0;
            --card-shadow: 0 1px 3px rgba(0,0,0,0.05);
            --card-shadow-hover: 0 10px 25px -5px rgba(0,0,0,0.08);
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            border-radius: 1rem;
            padding: 1.5rem;
            transition: all 0.3s ease;
            border: 1px solid var(--border);
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
            background: linear-gradient(135deg, var(--primary), var(--secondary));
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--card-shadow-hover);
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 800;
            color: var(--dark);
        }
        
        .stat-label {
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--gray);
            margin-top: 0.5rem;
        }
        
        .listing-card {
            background: white;
            border-radius: 1rem;
            margin-bottom: 1.5rem;
            overflow: hidden;
            border: 1px solid var(--border);
        }
        
        .listing-card:hover {
            box-shadow: var(--card-shadow-hover);
        }
        
        .card-header {
            padding: 1.25rem 1.5rem;
            background: var(--gray-light);
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .listing-info h3 {
            font-size: 1rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }
        
        .listing-meta {
            display: flex;
            gap: 1rem;
            font-size: 0.7rem;
            color: var(--gray);
            flex-wrap: wrap;
        }
        
        .listing-price {
            font-size: 1.25rem;
            font-weight: 800;
            color: var(--primary);
        }
        
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.75rem;
            border-radius: 2rem;
            font-size: 0.7rem;
            font-weight: 600;
        }
        
        .badge-pending { background: var(--warning-light); color: #92400e; }
        .badge-negotiating { background: var(--info-light); color: #1e40af; }
        .badge-payment { background: var(--success-light); color: #065f46; }
        
        .negotiation-box {
            padding: 1.25rem 1.5rem;
            background: #fafcff;
            border-bottom: 1px solid var(--border);
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
            gap: 0.25rem;
        }
        
        .offer-label {
            font-size: 0.7rem;
            font-weight: 500;
            color: var(--gray);
            text-transform: uppercase;
        }
        
        .offer-value {
            font-size: 1.125rem;
            font-weight: 700;
        }
        
        .offer-value.proposed { color: var(--primary); }
        .offer-value.counter { color: var(--warning); }
        
        .counter-message {
            background: var(--warning-light);
            border-radius: 0.75rem;
            padding: 0.75rem 1rem;
            font-size: 0.8rem;
            margin-top: 1rem;
            border-left: 3px solid var(--warning);
        }
        
        .btn-group {
            padding: 1rem 1.5rem;
            background: white;
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            border-top: 1px solid var(--border);
        }
        
        .btn {
            padding: 0.5rem 1.25rem;
            border-radius: 2rem;
            font-weight: 600;
            font-size: 0.8rem;
            cursor: pointer;
            transition: all 0.2s ease;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(79,70,229,0.3);
        }
        
        .btn-success {
            background: var(--success);
            color: white;
        }
        
        .btn-success:hover {
            background: #059669;
        }
        
        .btn-danger {
            background: var(--danger);
            color: white;
        }
        
        .btn-outline {
            background: transparent;
            border: 1px solid var(--border);
            color: var(--gray);
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            backdrop-filter: blur(4px);
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }
        
        .modal-content {
            background: white;
            border-radius: 1.5rem;
            padding: 1.75rem;
            width: 520px;
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
            margin-bottom: 1.25rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--border);
        }
        
        .close-modal {
            cursor: pointer;
            font-size: 1.5rem;
            color: var(--gray);
        }
        
        .close-modal:hover {
            color: var(--danger);
        }
        
        .form-group {
            margin-bottom: 1rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            font-size: 0.8rem;
        }
        
        .form-group input, .form-group textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--border);
            border-radius: 0.75rem;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        
        .alert {
            padding: 0.75rem 1rem;
            border-radius: 0.75rem;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            animation: slideIn 0.3s ease;
        }
        
        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .alert-success {
            background: var(--success-light);
            color: #065f46;
            border-left: 4px solid var(--success);
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
            background: white;
            border-radius: 1rem;
            border: 1px solid var(--border);
        }
        
        .empty-state-icon {
            font-size: 4rem;
            color: #cbd5e1;
            margin-bottom: 1rem;
        }
        
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            .card-header {
                flex-direction: column;
                align-items: flex-start;
            }
            .offer-grid {
                flex-direction: column;
                gap: 0.75rem;
            }
            .btn-group {
                flex-direction: column;
            }
            .btn {
                justify-content: center;
            }
        }
    </style>
</head>

<div>
    <!-- Stats Grid -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?php echo $stats['pending']; ?></div>
            <div class="stat-label">Pending Approval</div>
            <div class="stat-icon"><i class="fas fa-clock"></i></div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $stats['negotiating']; ?></div>
            <div class="stat-label">Under Negotiation</div>
            <div class="stat-icon"><i class="fas fa-handshake"></i></div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $stats['pending_payment']; ?></div>
            <div class="stat-label">Awaiting Payment</div>
            <div class="stat-icon"><i class="fas fa-credit-card"></i></div>
        </div>
    </div>
    
    <?php if ($message): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> <?php echo $message; ?>
        </div>
    <?php endif; ?>
    
    <!-- Pending Approval Listings -->
    <h2 style="margin-bottom: 1rem; font-size: 1.25rem;">Listings Awaiting Approval</h2>
    
    <?php if ($pendingListings && $pendingListings->num_rows > 0): ?>
        <?php while($listing = $pendingListings->fetch_assoc()): 
            $has_negotiation = $listing['negotiation_id'];
            $is_proposed = ($listing['negotiation_status'] == 'commission_proposed');
            $has_counter = ($listing['negotiation_status'] == 'counter_offer_sent');
        ?>
            <div class="listing-card">
                <div class="card-header">
                    <div class="listing-info">
                        <h3><?php echo htmlspecialchars($listing['title']); ?></h3>
                        <div class="listing-meta">
                            <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($listing['seller_name']); ?></span>
                            <span><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($listing['seller_email']); ?></span>
                            <span class="badge badge-pending"><i class="fas fa-hourglass-half"></i> Pending Review</span>
                        </div>
                    </div>
                    <div class="listing-price"><?php echo formatMoney($listing['price']); ?></div>
                </div>
                
                <?php if ($listing['proposed_commission']): ?>
                <div class="negotiation-box">
                    <div class="offer-grid">
                        <div class="offer-item">
                            <span class="offer-label">Proposed Commission</span>
                            <span class="offer-value proposed"><?php echo $listing['proposed_commission']; ?>%</span>
                        </div>
                        <div class="offer-item">
                            <span class="offer-label">Proposed Deposit</span>
                            <span class="offer-value proposed"><?php echo formatMoney($listing['proposed_deposit']); ?></span>
                        </div>
                        <?php if ($listing['counter_commission']): ?>
                        <div class="offer-item">
                            <span class="offer-label">Seller Counter Offer</span>
                            <span class="offer-value counter"><?php echo $listing['counter_commission']; ?>% / <?php echo formatMoney($listing['counter_deposit']); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($listing['counter_message']): ?>
                    <div class="counter-message">
                        <i class="fas fa-comment-dots"></i> <strong>Seller's Note:</strong> <?php echo htmlspecialchars($listing['counter_message']); ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
                <div class="btn-group">
                    <?php if (!$has_negotiation): ?>
                        <button onclick="openProposeModal(<?php echo $listing['id']; ?>, <?php echo $listing['price']; ?>)" class="btn btn-primary">
                            <i class="fas fa-percent"></i> Propose Terms
                        </button>
                        
                    <?php elseif ($has_counter): ?>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="negotiation_id" value="<?php echo $listing['negotiation_id']; ?>">
                            <input type="hidden" name="action" value="accept_counter">
                            <button type="submit" name="accept_counter" class="btn btn-success" onclick="return confirm('Accept this counter offer?')">
                                <i class="fas fa-check"></i> Accept Counter Offer
                            </button>
                        </form>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="negotiation_id" value="<?php echo $listing['negotiation_id']; ?>">
                            <input type="hidden" name="action" value="reject_counter">
                            <button type="submit" name="reject_counter" class="btn btn-danger" onclick="return confirm('Reject this counter offer?')">
                                <i class="fas fa-times"></i> Reject Counter Offer
                            </button>
                        </form>
                        
                    <?php elseif ($is_proposed): ?>
                        <button class="btn btn-outline" disabled>
                            <i class="fas fa-hourglass-half"></i> Waiting for Seller Response
                        </button>
                        
                        <button onclick="openProposeModal(<?php echo $listing['id']; ?>, <?php echo $listing['price']; ?>)" class="btn btn-primary">
                            <i class="fas fa-edit"></i> Modify Offer
                        </button>
                    <?php endif; ?>
                    
                    <a href="/broker_system/user/product.php?id=<?php echo $listing['id']; ?>" target="_blank" class="btn btn-outline">
                        <i class="fas fa-eye"></i> View Listing
                    </a>
                </div>
            </div>
        <?php endwhile; ?>
    <?php else: ?>
        <div class="empty-state">
            <div class="empty-state-icon">
                <i class="fas fa-check-circle"></i>
            </div>
            <h3>No Pending Listings</h3>
            <p>All listings have been processed.</p>
        </div>
    <?php endif; ?>
    
    <!-- Listings Awaiting Payment -->
    <?php if ($paymentNeeded && $paymentNeeded->num_rows > 0): ?>
    <h2 style="margin: 2rem 0 1rem; font-size: 1.25rem;">Listings Awaiting Payment</h2>
    
    <?php while($listing = $paymentNeeded->fetch_assoc()): ?>
        <div class="listing-card">
            <div class="card-header">
                <div class="listing-info">
                    <h3><?php echo htmlspecialchars($listing['title']); ?></h3>
                    <div class="listing-meta">
                        <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($listing['seller_name']); ?></span>
                        <span><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($listing['seller_email']); ?></span>
                        <span class="badge badge-payment"><i class="fas fa-credit-card"></i> Awaiting Payment</span>
                    </div>
                </div>
                <div class="listing-price"><?php echo formatMoney($listing['price']); ?></div>
            </div>
            
            <?php if ($listing['proposed_commission']): ?>
            <div class="negotiation-box">
                <div class="offer-grid">
                    <div class="offer-item">
                        <span class="offer-label">Agreed Commission</span>
                        <span class="offer-value proposed"><?php echo $listing['proposed_commission']; ?>%</span>
                    </div>
                    <div class="offer-item">
                        <span class="offer-label">Deposit Required</span>
                        <span class="offer-value proposed"><?php echo formatMoney($listing['proposed_deposit']); ?></span>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="btn-group">
                <a href="/broker_system/user/pay_listing.php?listing_id=<?php echo $listing['id']; ?>" class="btn btn-success" target="_blank">
                    <i class="fas fa-credit-card"></i> Help Seller Pay
                </a>
                <a href="/broker_system/user/product.php?id=<?php echo $listing['id']; ?>" target="_blank" class="btn btn-outline">
                    <i class="fas fa-eye"></i> View Listing
                </a>
            </div>
        </div>
    <?php endwhile; ?>
    <?php endif; ?>
</div>

<!-- Propose Modal -->
<div id="proposeModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-percent"></i> Propose Commission & Deposit</h3>
            <span class="close-modal" onclick="closeModal()">&times;</span>
        </div>
        <form method="POST">
            <input type="hidden" name="listing_id" id="proposeListingId">
            <input type="hidden" name="action" value="propose_terms">
            
            <div class="form-group">
                <label>Listing Price</label>
                <input type="text" id="modalPrice" disabled style="background: #f8fafc;">
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Commission (%)</label>
                    <input type="number" name="commission_percent" id="modalCommission" step="0.5" min="1" max="20" required>
                    <div class="info-text" id="commissionHint"></div>
                </div>
                <div class="form-group">
                    <label>Deposit Amount (ETB)</label>
                    <input type="number" name="deposit_amount" id="modalDeposit" step="100" min="0" required>
                </div>
            </div>
            
            <div class="form-group">
                <label>Admin Notes (Optional)</label>
                <textarea name="admin_notes" rows="3" placeholder="Add any notes for the seller..."></textarea>
            </div>
            
            <button type="submit" name="propose_terms" class="btn btn-primary" style="width: 100%;">
                <i class="fas fa-paper-plane"></i> Send Proposal to Seller
            </button>
        </form>
    </div>
</div>

<style>
    .info-text {
        font-size: 0.7rem;
        color: var(--gray);
        margin-top: 0.25rem;
    }
    h2 {
        font-size: 1.1rem;
        font-weight: 600;
        color: var(--dark);
        margin-bottom: 1rem;
    }
</style>

<script>
function openProposeModal(listingId, price) {
    document.getElementById('proposeListingId').value = listingId;
    document.getElementById('modalPrice').value = formatMoney(price);
    
    let recommendedCommission = 5;
    if (price > 2000000) recommendedCommission = 3;
    else if (price >= 500000) recommendedCommission = 5;
    else recommendedCommission = 7;
    
    document.getElementById('modalCommission').value = recommendedCommission;
    document.getElementById('commissionHint').innerHTML = `<i class="fas fa-robot"></i> AI Recommendation: ${recommendedCommission}% based on listing value`;
    
    let recommendedDeposit = price * 0.25;
    if (recommendedDeposit > 50000) recommendedDeposit = 50000;
    document.getElementById('modalDeposit').value = Math.round(recommendedDeposit);
    
    document.getElementById('proposeModal').style.display = 'flex';
}

function closeModal() {
    document.getElementById('proposeModal').style.display = 'none';
}

function formatMoney(amount) {
    return new Intl.NumberFormat('en-US', { minimumFractionDigits: 2 }).format(amount) + ' ETB';
}

window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
}
</script>

<?php
$content = ob_get_clean();
include 'layout.php';
?>

BRS/admin/chat.php

<?php
// admin/chat.php - Admin Chat Interface (Fixed - No Self Chat)

$page_title = 'Admin Messages';
ob_start();

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/chat_functions.php';

// Check if logged in and is admin/broker
if (!isLoggedIn() || ($_SESSION['user_role'] != 'admin' && $_SESSION['user_role'] != 'broker')) {
    header('Location: /broker_system/auth/login.php');
    exit;
}

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];
$conversation_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// IMPORTANT: Exclude admin's own account - only get regular users
$users = $conn->query("
    SELECT id, full_name, email 
    FROM users 
    WHERE role = 'user' 
    AND id != $user_id
    ORDER BY full_name
");

// Get admin's conversations (filtered to exclude self in chat_functions)
$conversations = getUserConversations($conn, $user_id);

// Get conversation messages
$messages = [];
$current_conversation = null;
if ($conversation_id > 0) {
    $current_conversation = getConversationById($conn, $conversation_id, $user_id);
    
    if ($current_conversation && $current_conversation['other_user_id'] != $user_id) {
        $messages = getMessagesWithDeleteFilter($conn, $conversation_id, $user_id, 100, 0);
        markMessagesAsRead($conn, $conversation_id, $user_id);
    } else {
        // Invalid conversation, redirect
        header('Location: chat.php');
        exit;
    }
}

$unread_count = getUnreadMessageCount($conn, $user_id);
$conn->close();
?>

<!-- HTML and CSS (same as previous, keeping it clean) -->
<style>
    :root {
        --primary: #667eea;
        --primary-dark: #5a67d8;
        --secondary: #764ba2;
        --success: #10b981;
        --warning: #f59e0b;
        --danger: #ef4444;
        --dark: #1e293b;
        --gray: #64748b;
        --light: #f8fafc;
        --border: #e2e8f0;
    }
    
    .chat-full-container {
        display: flex;
        gap: 0;
        background: white;
        border-radius: 24px;
        overflow: hidden;
        box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        min-height: calc(100vh - 200px);
    }
    
    .chat-sidebar-modern {
        width: 340px;
        background: white;
        border-right: 1px solid var(--border);
        display: flex;
        flex-direction: column;
        flex-shrink: 0;
    }
    
    .sidebar-header-modern {
        padding: 24px;
        border-bottom: 1px solid var(--border);
    }
    
    .sidebar-header-modern h2 {
        font-size: 20px;
        font-weight: 700;
        color: var(--dark);
        margin-bottom: 16px;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .new-chat-btn-modern {
        width: 100%;
        padding: 12px;
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        color: white;
        border: none;
        border-radius: 40px;
        font-weight: 600;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        transition: all 0.3s;
    }
    
    .new-chat-btn-modern:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(102,126,234,0.4);
    }
    
    .search-box-modern {
        padding: 16px 20px;
        border-bottom: 1px solid var(--border);
    }
    
    .search-box-modern input {
        width: 100%;
        padding: 10px 16px;
        border: 1px solid var(--border);
        border-radius: 40px;
        font-size: 13px;
        background: var(--light);
        transition: all 0.3s;
    }
    
    .search-box-modern input:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
    }
    
    .conversations-list-modern {
        flex: 1;
        overflow-y: auto;
    }
    
    .conversation-item-modern {
        display: flex;
        align-items: center;
        padding: 16px 20px;
        gap: 14px;
        cursor: pointer;
        transition: all 0.2s;
        border-bottom: 1px solid var(--border);
        position: relative;
    }
    
    .conversation-item-modern:hover {
        background: var(--light);
    }
    
    .conversation-item-modern.active {
        background: linear-gradient(135deg, rgba(102,126,234,0.08), rgba(118,75,162,0.08));
        border-left: 3px solid var(--primary);
    }
    
    .conversation-avatar-modern {
        width: 52px;
        height: 52px;
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: 600;
        font-size: 18px;
        flex-shrink: 0;
    }
    
    .conversation-info-modern {
        flex: 1;
        min-width: 0;
    }
    
    .conversation-name-modern {
        font-weight: 600;
        font-size: 15px;
        color: var(--dark);
        margin-bottom: 4px;
    }
    
    .conversation-last-modern {
        font-size: 12px;
        color: var(--gray);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    
    .conversation-meta-modern {
        text-align: right;
        flex-shrink: 0;
    }
    
    .conversation-time-modern {
        font-size: 10px;
        color: var(--gray);
    }
    
    .unread-badge-modern {
        background: var(--danger);
        color: white;
        font-size: 10px;
        font-weight: 600;
        padding: 3px 8px;
        border-radius: 20px;
        min-width: 20px;
        text-align: center;
        display: inline-block;
        margin-top: 6px;
    }
    
    .chat-main-modern {
        flex: 1;
        display: flex;
        flex-direction: column;
        background: var(--light);
    }
    
    .chat-header-modern {
        padding: 20px 24px;
        background: white;
        border-bottom: 1px solid var(--border);
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 12px;
    }
    
    .chat-header-left-modern {
        display: flex;
        align-items: center;
        gap: 14px;
    }
    
    .back-btn-modern {
        display: none;
        background: none;
        border: none;
        font-size: 20px;
        cursor: pointer;
        color: var(--gray);
        transition: color 0.3s;
    }
    
    .back-btn-modern:hover {
        color: var(--primary);
    }
    
    .chat-avatar-modern {
        width: 48px;
        height: 48px;
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: 600;
        font-size: 20px;
    }
    
    .chat-header-info-modern h3 {
        font-size: 18px;
        font-weight: 700;
        color: var(--dark);
        margin-bottom: 4px;
    }
    
    .typing-status-modern {
        font-size: 11px;
        color: var(--primary);
        min-height: 18px;
    }
    
    .typing-indicator-modern {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 4px 0;
    }
    
    .typing-dot-modern {
        width: 6px;
        height: 6px;
        background: var(--primary);
        border-radius: 50%;
        animation: typingAnimation 1.4s infinite ease-in-out;
    }
    
    @keyframes typingAnimation {
        0%, 60%, 100% { transform: translateY(0); opacity: 0.4; }
        30% { transform: translateY(-6px); opacity: 1; }
    }
    
    .chat-actions-modern {
        display: flex;
        align-items: center;
        gap: 12px;
    }
    
    .dashboard-link-modern {
        background: var(--success);
        color: white;
        padding: 8px 16px;
        border-radius: 40px;
        text-decoration: none;
        font-size: 13px;
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 6px;
        transition: all 0.3s;
    }
    
    .dashboard-link-modern:hover {
        background: #059669;
        transform: translateY(-2px);
    }
    
    .clear-history-btn-modern {
        background: none;
        border: 1px solid var(--border);
        padding: 8px 14px;
        border-radius: 40px;
        font-size: 12px;
        color: var(--gray);
        cursor: pointer;
        transition: all 0.3s;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    
    .clear-history-btn-modern:hover {
        background: #fee2e2;
        border-color: var(--danger);
        color: var(--danger);
    }
    
    .messages-area-modern {
        flex: 1;
        overflow-y: auto;
        padding: 24px;
        display: flex;
        flex-direction: column;
        gap: 16px;
    }
    
    .message-modern {
        display: flex;
        max-width: 70%;
        animation: fadeIn 0.3s ease;
    }
    
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    
    .message-modern.sent {
        align-self: flex-end;
    }
    
    .message-modern.received {
        align-self: flex-start;
    }
    
    .message-bubble-modern {
        padding: 12px 16px;
        border-radius: 20px;
        position: relative;
        word-wrap: break-word;
        max-width: 100%;
    }
    
    .message-modern.sent .message-bubble-modern {
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        color: white;
        border-bottom-right-radius: 4px;
    }
    
    .message-modern.received .message-bubble-modern {
        background: white;
        color: var(--dark);
        border-bottom-left-radius: 4px;
        box-shadow: 0 1px 2px rgba(0,0,0,0.05);
    }
    
    .message-text-modern {
        font-size: 14px;
        line-height: 1.5;
    }
    
    .message-time-modern {
        font-size: 9px;
        margin-top: 6px;
        opacity: 0.7;
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 10px;
    }
    
    .delete-msg-btn-modern {
        background: none;
        border: none;
        color: rgba(255,255,255,0.6);
        cursor: pointer;
        font-size: 10px;
        opacity: 0;
        transition: opacity 0.3s;
    }
    
    .message-modern.received .delete-msg-btn-modern {
        color: var(--gray);
    }
    
    .message-bubble-modern:hover .delete-msg-btn-modern {
        opacity: 1;
    }
    
    .delete-msg-btn-modern:hover {
        color: var(--danger) !important;
    }
    
    .message-reactions-modern {
        display: flex;
        gap: 6px;
        margin-top: 8px;
        flex-wrap: wrap;
    }
    
    .reaction-btn-modern {
        background: rgba(0,0,0,0.05);
        border: none;
        cursor: pointer;
        font-size: 11px;
        padding: 2px 8px;
        border-radius: 20px;
        transition: all 0.2s;
    }
    
    .message-modern.sent .reaction-btn-modern {
        background: rgba(255,255,255,0.2);
        color: white;
    }
    
    .message-modern.received .reaction-btn-modern {
        background: #f1f5f9;
        color: var(--dark);
    }
    
    .reaction-btn-modern:hover {
        transform: scale(1.1);
    }
    
    .reaction-btn-modern.active {
        background: var(--primary);
        color: white;
    }
    
    .message-modern.sent .reaction-btn-modern.active {
        background: white;
        color: var(--primary);
    }
    
    .chat-input-area-modern {
        padding: 20px 24px;
        background: white;
        border-top: 1px solid var(--border);
        display: flex;
        align-items: flex-end;
        gap: 12px;
    }
    
    .chat-input-modern {
        flex: 1;
        padding: 12px 18px;
        border: 1px solid var(--border);
        border-radius: 24px;
        font-size: 14px;
        resize: none;
        font-family: inherit;
        max-height: 120px;
        transition: all 0.3s;
    }
    
    .chat-input-modern:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
    }
    
    .send-btn-modern {
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        border: none;
        width: 44px;
        height: 44px;
        border-radius: 50%;
        color: white;
        cursor: pointer;
        transition: all 0.3s;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    
    .send-btn-modern:hover {
        transform: scale(1.05);
        box-shadow: 0 4px 12px rgba(102,126,234,0.4);
    }
    
    .empty-state-modern {
        display: flex;
        align-items: center;
        justify-content: center;
        height: 100%;
        color: var(--gray);
        flex-direction: column;
        gap: 20px;
        text-align: center;
        padding: 40px;
    }
    
    .empty-state-modern i {
        font-size: 64px;
        color: #cbd5e1;
    }
    
    .empty-state-modern h3 {
        font-size: 20px;
        color: var(--dark);
    }
    
    .modal-modern {
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
    
    .modal-content-modern {
        background: white;
        border-radius: 28px;
        padding: 28px;
        width: 450px;
        max-width: 90%;
        animation: modalIn 0.3s ease;
    }
    
    @keyframes modalIn {
        from { opacity: 0; transform: scale(0.9); }
        to { opacity: 1; transform: scale(1); }
    }
    
    .modal-header-modern {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
    }
    
    .modal-header-modern h3 {
        font-size: 20px;
        font-weight: 600;
        color: var(--dark);
    }
    
    .close-modal-modern {
        cursor: pointer;
        font-size: 28px;
        color: var(--gray);
        transition: color 0.3s;
    }
    
    .close-modal-modern:hover {
        color: var(--danger);
    }
    
    .user-list-modern {
        max-height: 400px;
        overflow-y: auto;
    }
    
    .user-item-modern {
        display: flex;
        align-items: center;
        gap: 14px;
        padding: 14px;
        cursor: pointer;
        border-radius: 16px;
        transition: all 0.2s;
    }
    
    .user-item-modern:hover {
        background: var(--light);
    }
    
    @media (max-width: 1024px) {
        .chat-sidebar-modern { width: 300px; }
    }
    
    @media (max-width: 768px) {
        .chat-full-container { flex-direction: column; min-height: calc(100vh - 160px); }
        .chat-sidebar-modern { width: 100%; display: none; }
        .chat-sidebar-modern.open { display: flex; position: absolute; z-index: 100; height: calc(100vh - 160px); background: white; }
        .back-btn-modern { display: block; }
        .message-modern { max-width: 85%; }
        .chat-header-modern { padding: 16px 20px; }
        .dashboard-link-modern span { display: none; }
        .dashboard-link-modern i { margin: 0; }
        .chat-actions-modern { gap: 8px; }
        .clear-history-btn-modern span { display: none; }
    }
</style>

<div class="chat-full-container">
    <!-- Sidebar -->
    <div class="chat-sidebar-modern" id="chatSidebar">
        <div class="sidebar-header-modern">
            <h2><i class="fas fa-comments"></i> Messages</h2>
            <button class="new-chat-btn-modern" onclick="openNewChatModal()">
                <i class="fas fa-plus"></i> New Conversation
            </button>
        </div>
        <div class="search-box-modern">
            <input type="text" id="searchConversations" placeholder="Search conversations..." onkeyup="filterConversations(this.value)">
        </div>
        <div class="conversations-list-modern" id="conversationsList">
            <?php if ($conversations && $conversations->num_rows > 0): ?>
                <?php 
                // Handle the custom result set
                $convs = [];
                if (isset($conversations->data)) {
                    $convs = $conversations->data;
                } else {
                    while($conv = $conversations->fetch_assoc()) {
                        $convs[] = $conv;
                    }
                }
                foreach($convs as $conv): 
                ?>
                    <div class="conversation-item-modern <?php echo $conversation_id == $conv['id'] ? 'active' : ''; ?>" 
                         onclick="loadConversation(<?php echo $conv['id']; ?>)"
                         data-conv-id="<?php echo $conv['id']; ?>"
                         data-conv-name="<?php echo strtolower($conv['other_user_name']); ?>">
                        <div class="conversation-avatar-modern">
                            <?php echo strtoupper(substr($conv['other_user_name'], 0, 1)); ?>
                        </div>
                        <div class="conversation-info-modern">
                            <div class="conversation-name-modern"><?php echo htmlspecialchars($conv['other_user_name']); ?></div>
                            <div class="conversation-last-modern"><?php echo htmlspecialchars(substr($conv['last_message'] ?? '', 0, 35)); ?></div>
                        </div>
                        <div class="conversation-meta-modern">
                            <div class="conversation-time-modern">
                                <?php 
                                if ($conv['last_message_time']) {
                                    echo date('H:i', strtotime($conv['last_message_time']));
                                }
                                ?>
                            </div>
                            <?php if ($conv['unread_count'] > 0): ?>
                                <div class="unread-badge-modern"><?php echo $conv['unread_count']; ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div style="padding: 60px 20px; text-align: center; color: var(--gray);">
                    <i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 16px; display: block;"></i>
                    <p>No messages yet</p>
                    <p style="font-size: 12px; margin-top: 8px;">Start a conversation with users</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Main Chat Area -->
    <div class="chat-main-modern">
        <?php if ($current_conversation): ?>
            <div class="chat-header-modern">
                <div class="chat-header-left-modern">
                    <button class="back-btn-modern" onclick="toggleSidebar()"><i class="fas fa-arrow-left"></i></button>
                    <div class="chat-avatar-modern">
                        <?php echo strtoupper(substr($current_conversation['other_user_name'], 0, 1)); ?>
                    </div>
                    <div class="chat-header-info-modern">
                        <h3><?php echo htmlspecialchars($current_conversation['other_user_name']); ?></h3>
                        <div class="typing-status-modern" id="typingStatus"></div>
                    </div>
                </div>
                <div class="chat-actions-modern">
                    <a href="dashboard.php" class="dashboard-link-modern">
                        <i class="fas fa-home"></i> <span>Dashboard</span>
                    </a>
                    <button class="clear-history-btn-modern" onclick="clearChatHistory()">
                        <i class="fas fa-trash-alt"></i> <span>Clear</span>
                    </button>
                </div>
            </div>

            <div class="messages-area-modern" id="messagesArea">
                <?php foreach($messages as $msg): ?>
                    <?php
                    $isSent = ($msg['sender_id'] == $user_id);
                    $reactionTypes = ['like' => '👍', 'dislike' => '👎', 'love' => '❤️', 'laugh' => '😂'];
                    ?>
                    <div class="message-modern <?php echo $isSent ? 'sent' : 'received'; ?>" data-msg-id="<?php echo $msg['id']; ?>">
                        <div class="message-bubble-modern">
                            <div class="message-text-modern"><?php echo nl2br(htmlspecialchars($msg['message'])); ?></div>
                            <div class="message-time-modern">
                                <?php echo date('H:i', strtotime($msg['created_at'])); ?>
                                <button class="delete-msg-btn-modern" onclick="deleteMessage(<?php echo $msg['id']; ?>)" title="Delete message">
                                    <i class="fas fa-trash-alt"></i>
                                </button>
                            </div>
                            <div class="message-reactions-modern">
                                <?php foreach($reactionTypes as $type => $emoji): ?>
                                    <?php $count = $msg['reactions'][$type] ?? 0; ?>
                                    <button class="reaction-btn-modern <?php echo ($msg['my_reaction'] == $type) ? 'active' : ''; ?>" onclick="addReaction(<?php echo $msg['id']; ?>, '<?php echo $type; ?>')">
                                        <?php echo $emoji; ?> <?php echo $count > 0 ? $count : ''; ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="chat-input-area-modern">
                <textarea class="chat-input-modern" id="messageInput" placeholder="Type a message..." rows="1"></textarea>
                <button class="send-btn-modern" onclick="sendMessage()">
                    <i class="fas fa-paper-plane"></i>
                </button>
            </div>
        <?php else: ?>
            <div class="empty-state-modern">
                <i class="fas fa-comments"></i>
                <h3>Select a conversation</h3>
                <p>Choose a chat to start messaging or start a new conversation</p>
                <div style="display: flex; gap: 12px; margin-top: 8px;">
                    <a href="dashboard.php" class="dashboard-link-modern" style="background: var(--success);">
                        <i class="fas fa-home"></i> Go to Dashboard
                    </a>
                    <button class="new-chat-btn-modern" onclick="openNewChatModal()" style="width: auto; padding: 10px 24px;">
                        <i class="fas fa-plus"></i> New Chat
                    </button>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- New Chat Modal -->
<div id="newChatModal" class="modal-modern">
    <div class="modal-content-modern">
        <div class="modal-header-modern">
            <h3><i class="fas fa-plus-circle"></i> Start New Conversation</h3>
            <span class="close-modal-modern" onclick="closeNewChatModal()">&times;</span>
        </div>
        <div class="user-list-modern">
            <input type="text" id="searchUsers" placeholder="Search users..." style="width: 100%; padding: 10px; margin-bottom: 16px; border: 1px solid var(--border); border-radius: 40px; padding-left: 16px;" onkeyup="filterUsers(this.value)">
            <div id="usersList">
                <?php if ($users && $users->num_rows > 0): ?>
                    <?php while($user = $users->fetch_assoc()): ?>
                        <div class="user-item-modern" data-user-name="<?php echo strtolower($user['full_name']); ?>" onclick="startConversation(<?php echo $user['id']; ?>)">
                            <div class="conversation-avatar-modern" style="width: 44px; height: 44px; font-size: 18px;">
                                <?php echo strtoupper(substr($user['full_name'], 0, 1)); ?>
                            </div>
                            <div>
                                <div style="font-weight: 600;"><?php echo htmlspecialchars($user['full_name']); ?></div>
                                <div style="font-size: 12px; color: var(--gray);"><?php echo htmlspecialchars($user['email']); ?></div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div style="text-align: center; padding: 40px; color: var(--gray);">
                        <i class="fas fa-users" style="font-size: 48px; margin-bottom: 16px; display: block;"></i>
                        <p>No users found</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Clear History Confirmation Modal -->
<div id="clearHistoryModal" class="modal-modern">
    <div class="modal-content-modern">
        <div class="modal-header-modern">
            <h3><i class="fas fa-trash-alt"></i> Clear Chat History</h3>
            <span class="close-modal-modern" onclick="closeClearHistoryModal()">&times;</span>
        </div>
        <p style="margin-bottom: 20px; color: var(--gray);">Are you sure you want to clear all messages in this conversation? This action cannot be undone.</p>
        <div style="display: flex; gap: 12px; justify-content: flex-end;">
            <button onclick="closeClearHistoryModal()" style="padding: 10px 20px; background: var(--gray); color: white; border: none; border-radius: 40px; cursor: pointer;">Cancel</button>
            <button onclick="confirmClearHistory()" style="padding: 10px 20px; background: var(--danger); color: white; border: none; border-radius: 40px; cursor: pointer;">Clear All</button>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
    let conversationId = <?php echo $conversation_id; ?>;
    let userId = <?php echo $user_id; ?>;
    let pollInterval;
    let typingTimeout;
    let typingCheckInterval;

    function scrollToBottom() {
        const messagesArea = document.getElementById('messagesArea');
        if (messagesArea) {
            messagesArea.scrollTop = messagesArea.scrollHeight;
        }
    }

    function loadConversation(id) {
        window.location.href = `chat.php?id=${id}`;
    }

    function filterConversations(searchTerm) {
        const items = document.querySelectorAll('.conversation-item-modern');
        const term = searchTerm.toLowerCase();
        
        items.forEach(item => {
            const name = item.getAttribute('data-conv-name') || '';
            if (name.includes(term)) {
                item.style.display = 'flex';
            } else {
                item.style.display = 'none';
            }
        });
    }

    function filterUsers(searchTerm) {
        const items = document.querySelectorAll('.user-item-modern');
        const term = searchTerm.toLowerCase();
        
        items.forEach(item => {
            const name = item.getAttribute('data-user-name') || '';
            if (name.includes(term)) {
                item.style.display = 'flex';
            } else {
                item.style.display = 'none';
            }
        });
    }

    function sendMessage() {
        const input = document.getElementById('messageInput');
        const message = input.value.trim();
        
        if (!message || !conversationId) return;
        
        const sendBtn = document.querySelector('.send-btn-modern');
        sendBtn.disabled = true;
        sendBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        
        $.ajax({
            url: '../user/api/send_message.php',
            method: 'POST',
            data: {
                conversation_id: conversationId,
                message: message
            },
            success: function(response) {
                if (response.success) {
                    input.value = '';
                    input.style.height = 'auto';
                    loadMessages();
                    updateConversationList();
                    setTimeout(scrollToBottom, 100);
                } else {
                    alert('Failed to send message');
                }
            },
            complete: function() {
                sendBtn.disabled = false;
                sendBtn.innerHTML = '<i class="fas fa-paper-plane"></i>';
            }
        });
    }

    function loadMessages() {
        if (!conversationId) return;
        
        $.ajax({
            url: '../user/api/get_messages.php',
            method: 'GET',
            data: { conversation_id: conversationId },
            success: function(response) {
                if (response.success && response.messages) {
                    const messagesArea = document.getElementById('messagesArea');
                    const currentMessageIds = Array.from(messagesArea.querySelectorAll('.message-modern')).map(el => parseInt(el.dataset.msgId));
                    const newMessages = response.messages.filter(msg => !currentMessageIds.includes(msg.id));
                    
                    if (newMessages.length > 0) {
                        newMessages.forEach(msg => {
                            appendMessage(msg);
                        });
                        scrollToBottom();
                        $.post('../user/api/mark_read.php', { conversation_id: conversationId });
                        updateConversationList();
                    } else if (currentMessageIds.length !== response.messages.length) {
                        messagesArea.innerHTML = '';
                        response.messages.forEach(msg => {
                            appendMessage(msg);
                        });
                        scrollToBottom();
                    }
                }
            }
        });
    }

    function appendMessage(msg) {
        const messagesArea = document.getElementById('messagesArea');
        const isSent = msg.sender_id == userId;
        const reactionTypes = { 'like': '👍', 'dislike': '👎', 'love': '❤️', 'laugh': '😂' };
        
        const messageDiv = document.createElement('div');
        messageDiv.className = `message-modern ${isSent ? 'sent' : 'received'}`;
        messageDiv.setAttribute('data-msg-id', msg.id);
        
        let reactionsHtml = '<div class="message-reactions-modern">';
        for (const [type, emoji] of Object.entries(reactionTypes)) {
            const count = msg.reactions[type] || 0;
            const isActive = (msg.my_reaction === type);
            reactionsHtml += `<button class="reaction-btn-modern ${isActive ? 'active' : ''}" onclick="addReaction(${msg.id}, '${type}')">${emoji} ${count > 0 ? count : ''}</button>`;
        }
        reactionsHtml += '</div>';
        
        messageDiv.innerHTML = `
            <div class="message-bubble-modern">
                <div class="message-text-modern">${escapeHtml(msg.message)}</div>
                <div class="message-time-modern">
                    ${msg.time}
                    <button class="delete-msg-btn-modern" onclick="deleteMessage(${msg.id})" title="Delete message">
                        <i class="fas fa-trash-alt"></i>
                    </button>
                </div>
                ${reactionsHtml}
            </div>
        `;
        messagesArea.appendChild(messageDiv);
    }

    function getEmojiForType(type) {
        const emojis = { 'like': '👍', 'dislike': '👎', 'love': '❤️', 'laugh': '😂' };
        return emojis[type] || '👍';
    }

    function addReaction(messageId, type) {
        const messageDiv = $(`.message-modern[data-msg-id="${messageId}"]`);
        const emoji = getEmojiForType(type);
        const reactionBtn = messageDiv.find('.reaction-btn-modern').filter(function() {
            return $(this).text().trim().startsWith(emoji);
        });
        
        const btnText = reactionBtn.text().trim();
        const currentCount = parseInt(btnText.match(/\d+/)?.[0] || 0);
        const isCurrentlyActive = reactionBtn.hasClass('active');
        
        if (isCurrentlyActive) {
            const newCount = currentCount - 1;
            reactionBtn.text(`${emoji} ${newCount > 0 ? newCount : ''}`);
            reactionBtn.removeClass('active');
        } else {
            const newCount = currentCount + 1;
            reactionBtn.text(`${emoji} ${newCount > 0 ? newCount : ''}`);
            reactionBtn.addClass('active');
            
            messageDiv.find('.reaction-btn-modern').not(reactionBtn).each(function() {
                const otherBtn = $(this);
                const otherText = otherBtn.text().trim();
                const otherCount = parseInt(otherText.match(/\d+/)?.[0] || 0);
                const otherEmoji = otherText.charAt(0);
                const newOtherCount = otherCount - 1;
                otherBtn.text(`${otherEmoji} ${newOtherCount > 0 ? newOtherCount : ''}`);
                otherBtn.removeClass('active');
            });
        }
        
        $.ajax({
            url: '../user/api/add_reaction.php',
            method: 'POST',
            data: { message_id: messageId, reaction_type: type },
            success: function(response) {
                if (response.success && response.reactions) {
                    syncReactions(messageId, response.reactions);
                }
            },
            error: function() {
                loadMessages();
            }
        });
    }

    function syncReactions(messageId, reactions) {
        const messageDiv = $(`.message-modern[data-msg-id="${messageId}"]`);
        const reactionTypes = ['like', 'dislike', 'love', 'laugh'];
        
        reactionTypes.forEach(type => {
            const count = reactions[type] || 0;
            const emoji = getEmojiForType(type);
            const btn = messageDiv.find('.reaction-btn-modern').filter(function() {
                return $(this).text().trim().startsWith(emoji);
            });
            btn.text(`${emoji} ${count > 0 ? count : ''}`);
        });
    }

    function deleteMessage(messageId) {
        if (confirm('Delete this message? It will be removed from your chat history.')) {
            $.ajax({
                url: '../user/api/delete_message.php',
                method: 'POST',
                data: { message_id: messageId },
                success: function(response) {
                    if (response.success) {
                        $(`.message-modern[data-msg-id="${messageId}"]`).remove();
                    } else {
                        alert('Failed to delete message');
                    }
                }
            });
        }
    }

    function clearChatHistory() {
        if (!conversationId) return;
        document.getElementById('clearHistoryModal').style.display = 'flex';
    }

    function closeClearHistoryModal() {
        document.getElementById('clearHistoryModal').style.display = 'none';
    }

    function confirmClearHistory() {
        if (!conversationId) return;
        
        $.ajax({
            url: '../user/api/clear_history.php',
            method: 'POST',
            data: { conversation_id: conversationId },
            success: function(response) {
                if (response.success) {
                    document.getElementById('messagesArea').innerHTML = '';
                    updateConversationList();
                    closeClearHistoryModal();
                    alert('Chat history cleared successfully');
                } else {
                    alert('Failed to clear history: ' + (response.error || 'Unknown error'));
                }
            },
            error: function() {
                alert('Failed to clear history');
            }
        });
    }

    function sendTyping() {
        if (!conversationId) return;
        
        if (typingTimeout) clearTimeout(typingTimeout);
        
        $.post('../user/api/typing.php', { conversation_id: conversationId, typing: true });
        
        typingTimeout = setTimeout(() => {
            $.post('../user/api/typing.php', { conversation_id: conversationId, typing: false });
        }, 2000);
    }

    function checkOtherUserTyping() {
        if (!conversationId) return;
        
        $.get('../user/api/typing.php', { conversation_id: conversationId }, function(response) {
            const typingStatus = document.getElementById('typingStatus');
            if (typingStatus) {
                if (response.typing && response.typing_user_id && response.typing_user_id != userId) {
                    typingStatus.innerHTML = '<div class="typing-indicator-modern"><span class="typing-dot-modern"></span><span class="typing-dot-modern"></span><span class="typing-dot-modern"></span> typing...</div>';
                } else {
                    typingStatus.innerHTML = '';
                }
            }
        });
    }

    function updateConversationList() {
        $.get('../user/api/get_conversations.php', function(response) {
            if (response.success && response.conversations) {
                response.conversations.forEach(conv => {
                    const item = document.querySelector(`.conversation-item-modern[data-conv-id="${conv.id}"]`);
                    if (item) {
                        const badge = item.querySelector('.unread-badge-modern');
                        if (conv.unread_count > 0) {
                            if (badge) badge.textContent = conv.unread_count;
                            else {
                                const meta = item.querySelector('.conversation-meta-modern');
                                if (meta) meta.innerHTML += `<div class="unread-badge-modern">${conv.unread_count}</div>`;
                            }
                        } else if (badge) badge.remove();
                        
                        const lastMsgElem = item.querySelector('.conversation-last-modern');
                        if (lastMsgElem && conv.last_message) {
                            lastMsgElem.textContent = conv.last_message.substring(0, 35);
                        }
                    }
                });
            }
        });
    }

    function startPolling() {
        if (pollInterval) clearInterval(pollInterval);
        pollInterval = setInterval(() => { loadMessages(); }, 3000);
    }

    function startTypingCheck() {
        if (typingCheckInterval) clearInterval(typingCheckInterval);
        typingCheckInterval = setInterval(() => { checkOtherUserTyping(); }, 2000);
    }

    const textarea = document.getElementById('messageInput');
    if (textarea) {
        textarea.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 100) + 'px';
            sendTyping();
        });
        textarea.addEventListener('keypress', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });
    }

    function openNewChatModal() { 
        document.getElementById('newChatModal').style.display = 'flex'; 
    }
    
    function closeNewChatModal() { 
        document.getElementById('newChatModal').style.display = 'none'; 
    }
    
    function startConversation(brokerId) { 
        // Prevent starting conversation with self
        if (brokerId == userId) {
            alert('You cannot start a conversation with yourself.');
            closeNewChatModal();
            return;
        }
        window.location.href = `../user/api/start_conversation.php?broker_id=${brokerId}`; 
    }
    
    function toggleSidebar() { 
        document.getElementById('chatSidebar').classList.toggle('open'); 
    }
    
    function escapeHtml(text) { 
        const div = document.createElement('div'); 
        div.textContent = text; 
        return div.innerHTML; 
    }

    if (conversationId) {
        startPolling();
        startTypingCheck();
        loadMessages();
        $.post('../user/api/mark_read.php', { conversation_id: conversationId });
        setTimeout(scrollToBottom, 500);
    }

    window.onclick = function(event) {
        const modal = document.getElementById('newChatModal');
        const clearModal = document.getElementById('clearHistoryModal');
        if (event.target === modal) modal.style.display = 'none';
        if (event.target === clearModal) clearModal.style.display = 'none';
    }
</script>

<?php
$content = ob_get_clean();
include 'layout.php';
?>

BRS/admin/companies.php

<?php
// admin/companies.php - Companies Management

$page_title = 'Companies Management';
ob_start();

require_once '../config/database.php';
require_once '../includes/functions.php';

$conn = getDbConnection();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['approve_company'])) {
        $companyId = intval($_POST['company_id']);
        $conn->query("UPDATE companies SET is_approved = 1 WHERE id = $companyId");
        $message = "Company approved successfully";
    }
    
    if (isset($_POST['update_subscription'])) {
        $companyId = intval($_POST['company_id']);
        $plan = $_POST['subscription_plan'];
        $expiry = $_POST['subscription_expiry'];
        $conn->query("UPDATE companies SET subscription_plan = '$plan', subscription_expiry = '$expiry' WHERE id = $companyId");
        $message = "Subscription updated successfully";
    }
}

$companies = $conn->query("
    SELECT c.*, u.full_name, u.email, u.phone, u.is_verified 
    FROM companies c 
    JOIN users u ON c.user_id = u.id 
    ORDER BY c.created_at DESC
");

$stats = [
    'total' => $conn->query("SELECT COUNT(*) as count FROM companies")->fetch_assoc()['count'],
    'approved' => $conn->query("SELECT COUNT(*) as count FROM companies WHERE is_approved = 1")->fetch_assoc()['count'],
    'pending' => $conn->query("SELECT COUNT(*) as count FROM companies WHERE is_approved = 0")->fetch_assoc()['count'],
    'subscribed' => $conn->query("SELECT COUNT(*) as count FROM companies WHERE subscription_plan != 'none'")->fetch_assoc()['count'],
];

$conn->close();
?>

<style>
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 20px;
        margin-bottom: 24px;
    }
    .stat-card {
        background: white;
        border-radius: 20px;
        padding: 20px;
        text-align: center;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    .stat-value { font-size: 32px; font-weight: 700; color: #0f172a; }
    .stat-label { font-size: 13px; color: #64748b; margin-top: 6px; }
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
        border-radius: 20px;
        padding: 24px;
        width: 400px;
    }
    .form-group { margin-bottom: 16px; }
    .form-group label { display: block; margin-bottom: 6px; font-weight: 500; }
    .form-group input, .form-group select { width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 10px; }
</style>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-value"><?php echo $stats['total']; ?></div><div class="stat-label">Total Companies</div></div>
    <div class="stat-card"><div class="stat-value"><?php echo $stats['approved']; ?></div><div class="stat-label">Approved</div></div>
    <div class="stat-card"><div class="stat-value"><?php echo $stats['pending']; ?></div><div class="stat-label">Pending Approval</div></div>
    <div class="stat-card"><div class="stat-value"><?php echo $stats['subscribed']; ?></div><div class="stat-label">Active Subscriptions</div></div>
</div>

<?php if ($message): ?>
<div class="alert alert-success" style="background:#d1fae5; color:#059669; padding:12px; border-radius:12px; margin-bottom:20px;"><?php echo $message; ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><h2><i class="fas fa-building"></i> All Companies</h2></div>
    <div class="table-wrapper">
        <table>
            <thead><tr><th>ID</th><th>Company Name</th><th>Contact Person</th><th>Email</th><th>Phone</th><th>Subscription</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
                <?php while($row = $companies->fetch_assoc()): ?>
                <tr>
                    <td>#<?php echo $row['id']; ?></td>
                    <td><strong><?php echo htmlspecialchars($row['business_name']); ?></strong></td>
                    <td><?php echo htmlspecialchars($row['full_name']); ?></td>
                    <td><?php echo htmlspecialchars($row['email']); ?></td>
                    <td><?php echo htmlspecialchars($row['phone'] ?? '-'); ?></td>
                    <td><span class="badge badge-info"><?php echo ucfirst($row['subscription_plan']); ?></span><br><small>Expires: <?php echo $row['subscription_expiry'] ?? 'N/A'; ?></small></td>
                    <td><?php echo $row['is_approved'] ? '<span class="badge badge-success">Approved</span>' : '<span class="badge badge-warning">Pending</span>'; ?></td>
                    <td>
                        <?php if (!$row['is_approved']): ?>
                        <form method="POST" style="display:inline;"><input type="hidden" name="company_id" value="<?php echo $row['id']; ?>"><button type="submit" name="approve_company" class="btn-sm btn-success">Approve</button></form>
                        <?php endif; ?>
                        <button onclick="openSubscriptionModal(<?php echo $row['id']; ?>, '<?php echo $row['subscription_plan']; ?>', '<?php echo $row['subscription_expiry']; ?>')" class="btn-sm btn-primary">Subscription</button>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="subscriptionModal" class="modal">
    <div class="modal-content">
        <div class="card-header"><h2>Manage Subscription</h2><span onclick="closeSubscriptionModal()" style="cursor:pointer;">&times;</span></div>
        <form method="POST">
            <input type="hidden" name="company_id" id="subCompanyId">
            <div class="form-group"><label>Plan</label><select name="subscription_plan" id="subPlan"><option value="none">None</option><option value="monthly">Monthly</option><option value="yearly">Yearly</option></select></div>
            <div class="form-group"><label>Expiry Date</label><input type="date" name="subscription_expiry" id="subExpiry"></div>
            <button type="submit" name="update_subscription" class="btn-sm btn-primary">Update</button>
            <button type="button" onclick="closeSubscriptionModal()" class="btn-sm">Cancel</button>
        </form>
    </div>
</div>

<script>
function openSubscriptionModal(id, plan, expiry) {
    document.getElementById('subCompanyId').value = id;
    document.getElementById('subPlan').value = plan || 'none';
    document.getElementById('subExpiry').value = expiry || '';
    document.getElementById('subscriptionModal').style.display = 'flex';
}
function closeSubscriptionModal() { document.getElementById('subscriptionModal').style.display = 'none'; }
window.onclick = function(event) { if (event.target.classList.contains('modal')) event.target.style.display = 'none'; }
</script>

<?php
$content = ob_get_clean();
include 'layout.php';
?>

BRS/admin/dashboard.php

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

BRS/admin/disputes.php

<?php
// admin/disputes.php - Dispute Resolution

$page_title = 'Dispute Resolution';
ob_start();

require_once '../config/database.php';
require_once '../includes/functions.php';

$conn = getDbConnection();
$message = '';
$error = '';

// Handle dispute actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_dispute'])) {
    $disputeId = intval($_POST['dispute_id']);
    $status = $_POST['status'];
    $decision = $conn->real_escape_string($_POST['admin_decision']);
    $decisionNotes = $conn->real_escape_string($_POST['decision_notes']);
    
    $stmt = $conn->prepare("UPDATE disputes SET status = ?, admin_decision = ?, decision_notes = ?, resolved_at = NOW() WHERE id = ?");
    $stmt->bind_param("sssi", $status, $decision, $decisionNotes, $disputeId);
    
    if ($stmt->execute()) {
        $disputeInfo = $conn->query("SELECT transaction_id FROM disputes WHERE id = $disputeId")->fetch_assoc();
        if ($status == 'resolved') {
            $conn->query("UPDATE transactions SET status = 'completed' WHERE id = {$disputeInfo['transaction_id']}");
        } elseif ($status == 'rejected') {
            $conn->query("UPDATE transactions SET status = 'deposits_complete' WHERE id = {$disputeInfo['transaction_id']}");
        }
        $message = "Dispute updated successfully";
    } else {
        $error = "Failed to update dispute";
    }
}

// Get disputes
$status = $_GET['status'] ?? '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$where = $status ? "WHERE d.status = '$status'" : "";
$sql = "SELECT d.*, t.total_amount, u.full_name as raised_by_name, u2.full_name as buyer_name, u3.full_name as seller_name
        FROM disputes d
        JOIN transactions t ON d.transaction_id = t.id
        JOIN users u ON d.raised_by = u.id
        JOIN users u2 ON t.buyer_id = u2.id
        JOIN users u3 ON t.seller_id = u3.id
        $where
        ORDER BY d.created_at DESC
        LIMIT $offset, $limit";
$disputes = $conn->query($sql);

$total = $conn->query("SELECT COUNT(*) as count FROM disputes $where")->fetch_assoc()['count'];
$totalPages = ceil($total / $limit);

// Get single dispute for view
$viewDispute = null;
if (isset($_GET['view'])) {
    $viewId = intval($_GET['view']);
    $viewDispute = $conn->query("
        SELECT d.*, t.*, u.full_name as raised_by_name, u2.full_name as buyer_name, u3.full_name as seller_name
        FROM disputes d
        JOIN transactions t ON d.transaction_id = t.id
        JOIN users u ON d.raised_by = u.id
        JOIN users u2 ON t.buyer_id = u2.id
        JOIN users u3 ON t.seller_id = u3.id
        WHERE d.id = $viewId
    ")->fetch_assoc();
}

$stats = [
    'open' => $conn->query("SELECT COUNT(*) as count FROM disputes WHERE status = 'open'")->fetch_assoc()['count'],
    'under_review' => $conn->query("SELECT COUNT(*) as count FROM disputes WHERE status = 'under_review'")->fetch_assoc()['count'],
    'resolved' => $conn->query("SELECT COUNT(*) as count FROM disputes WHERE status = 'resolved'")->fetch_assoc()['count'],
    'total' => $conn->query("SELECT COUNT(*) as count FROM disputes")->fetch_assoc()['count'],
];

$conn->close();
?>

<style>
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
        padding: 24px;
        text-align: center;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        transition: all 0.3s;
    }
    
    .stat-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 24px -8px rgba(0,0,0,0.15);
    }
    
    .stat-value {
        font-size: 32px;
        font-weight: 700;
    }
    
    .stat-card.open .stat-value { color: #ef4444; }
    .stat-card.review .stat-value { color: #f59e0b; }
    .stat-card.resolved .stat-value { color: #10b981; }
    
    .stat-label {
        font-size: 13px;
        color: #64748b;
        margin-top: 6px;
    }
    
    /* Filters */
    .filters {
        margin-bottom: 24px;
    }
    
    .filter-select {
        padding: 10px 16px;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        font-size: 14px;
        background: white;
        min-width: 180px;
        cursor: pointer;
    }
    
    /* Card */
    .card {
        background: white;
        border-radius: 20px;
        padding: 24px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    
    .card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        padding-bottom: 16px;
        border-bottom: 2px solid #f1f5f9;
    }
    
    .card-header h2 {
        font-size: 18px;
        font-weight: 600;
        color: #0f172a;
    }
    
    /* Table */
    .table-wrapper {
        overflow-x: auto;
    }
    
    table {
        width: 100%;
        border-collapse: collapse;
    }
    
    th, td {
        padding: 14px 12px;
        text-align: left;
        border-bottom: 1px solid #f1f5f9;
        font-size: 13px;
    }
    
    th {
        font-weight: 600;
        color: #64748b;
        background: #fafbfc;
        font-size: 12px;
        text-transform: uppercase;
    }
    
    tr:hover {
        background: #f8fafc;
        cursor: pointer;
    }
    
    /* Badges */
    .badge {
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 500;
        display: inline-block;
    }
    
    .badge-open { background: #fee2e2; color: #dc2626; }
    .badge-review { background: #fed7aa; color: #ea580c; }
    .badge-resolved { background: #d1fae5; color: #059669; }
    
    /* Buttons */
    .btn-sm {
        padding: 6px 12px;
        font-size: 12px;
        border-radius: 8px;
        border: none;
        cursor: pointer;
        text-decoration: none;
        display: inline-block;
        background: #667eea;
        color: white;
    }
    
    .dispute-detail {
        background: #f8fafc;
        border-radius: 16px;
        padding: 20px;
        margin-bottom: 24px;
    }
    
    .form-group {
        margin-bottom: 20px;
    }
    
    .form-group label {
        display: block;
        margin-bottom: 8px;
        font-weight: 500;
        color: #334155;
    }
    
    .form-group select, .form-group textarea {
        width: 100%;
        padding: 12px;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        font-family: inherit;
    }
    
    .form-group select:focus, .form-group textarea:focus {
        outline: none;
        border-color: #667eea;
    }
    
    .btn-save {
        background: #10b981;
        color: white;
        padding: 10px 24px;
        border: none;
        border-radius: 40px;
        font-weight: 600;
        cursor: pointer;
    }
    
    .pagination {
        display: flex;
        justify-content: center;
        gap: 8px;
        margin-top: 24px;
        padding-top: 16px;
        border-top: 1px solid #f1f5f9;
    }
    
    .pagination a, .pagination span {
        padding: 8px 14px;
        background: white;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        text-decoration: none;
        color: #475569;
        font-size: 13px;
    }
    
    .pagination a:hover, .pagination .active {
        background: #667eea;
        color: white;
        border-color: #667eea;
    }
    
    @media (max-width: 768px) {
        .stats-grid { grid-template-columns: repeat(2, 1fr); }
        th, td { padding: 10px 8px; font-size: 12px; }
        .card-header { flex-direction: column; gap: 12px; align-items: flex-start; }
    }
</style>

<!-- Statistics Cards -->
<div class="stats-grid">
    <div class="stat-card open">
        <div class="stat-value"><?php echo $stats['open']; ?></div>
        <div class="stat-label">Open</div>
    </div>
    <div class="stat-card review">
        <div class="stat-value"><?php echo $stats['under_review']; ?></div>
        <div class="stat-label">Under Review</div>
    </div>
    <div class="stat-card resolved">
        <div class="stat-value"><?php echo $stats['resolved']; ?></div>
        <div class="stat-label">Resolved</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['total']; ?></div>
        <div class="stat-label">Total</div>
    </div>
</div>

<!-- Filters -->
<div class="filters">
    <select class="filter-select" onchange="location.href='?status='+this.value">
        <option value="">All Status</option>
        <option value="open" <?php echo $status == 'open' ? 'selected' : ''; ?>>Open</option>
        <option value="under_review" <?php echo $status == 'under_review' ? 'selected' : ''; ?>>Under Review</option>
        <option value="resolved" <?php echo $status == 'resolved' ? 'selected' : ''; ?>>Resolved</option>
    </select>
</div>

<?php if ($viewDispute): ?>
    <!-- View Single Dispute -->
    <div class="card">
        <div class="card-header">
            <h2>Dispute #<?php echo $viewDispute['id']; ?></h2>
            <a href="disputes.php" class="btn-sm">← Back to List</a>
        </div>
        
        <div class="dispute-detail">
            <p><strong>Transaction ID:</strong> #<?php echo $viewDispute['transaction_id']; ?></p>
            <p><strong>Amount:</strong> <?php echo formatMoney($viewDispute['total_amount']); ?></p>
            <p><strong>Buyer:</strong> <?php echo htmlspecialchars($viewDispute['buyer_name']); ?></p>
            <p><strong>Seller:</strong> <?php echo htmlspecialchars($viewDispute['seller_name']); ?></p>
            <p><strong>Raised By:</strong> <?php echo htmlspecialchars($viewDispute['raised_by_name']); ?></p>
            <p><strong>Status:</strong> <span class="badge badge-<?php echo $viewDispute['status'] == 'open' ? 'open' : ($viewDispute['status'] == 'under_review' ? 'review' : 'resolved'); ?>"><?php echo ucfirst($viewDispute['status']); ?></span></p>
            <p><strong>Reason:</strong> <?php echo nl2br(htmlspecialchars($viewDispute['reason'])); ?></p>
            <?php if ($viewDispute['evidence']): ?>
                <p><strong>Evidence:</strong> <?php echo nl2br(htmlspecialchars($viewDispute['evidence'])); ?></p>
            <?php endif; ?>
        </div>
        
        <form method="POST">
            <input type="hidden" name="dispute_id" value="<?php echo $viewDispute['id']; ?>">
            
            <div class="form-group">
                <label>Status</label>
                <select name="status" required>
                    <option value="open" <?php echo $viewDispute['status'] == 'open' ? 'selected' : ''; ?>>Open</option>
                    <option value="under_review" <?php echo $viewDispute['status'] == 'under_review' ? 'selected' : ''; ?>>Under Review</option>
                    <option value="resolved" <?php echo $viewDispute['status'] == 'resolved' ? 'selected' : ''; ?>>Resolved - Release Payment</option>
                    <option value="rejected" <?php echo $viewDispute['status'] == 'rejected' ? 'selected' : ''; ?>>Rejected - Refund</option>
                </select>
            </div>
            
            <div class="form-group">
                <label>Admin Decision</label>
                <textarea name="admin_decision" rows="3" required><?php echo htmlspecialchars($viewDispute['admin_decision'] ?? ''); ?></textarea>
            </div>
            
            <div class="form-group">
                <label>Additional Notes</label>
                <textarea name="decision_notes" rows="2"><?php echo htmlspecialchars($viewDispute['decision_notes'] ?? ''); ?></textarea>
            </div>
            
            <button type="submit" name="update_dispute" class="btn-save">Update Dispute</button>
        </form>
    </div>
<?php else: ?>
    <!-- Disputes List -->
    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-gavel"></i> All Disputes</h2>
            <span><?php echo number_format($total); ?> disputes</span>
        </div>
        <div class="table-wrapper">
            <?php if ($disputes && $disputes->num_rows > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Transaction</th>
                            <th>Raised By</th>
                            <th>Reason</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($row = $disputes->fetch_assoc()): ?>
                            <tr onclick="location.href='?view=<?php echo $row['id']; ?>'">
                                <td>#<?php echo $row['id']; ?></td>
                                <td>#<?php echo $row['transaction_id']; ?></td>
                                <td><?php echo htmlspecialchars($row['raised_by_name']); ?></td>
                                <td><?php echo substr(htmlspecialchars($row['reason']), 0, 50); ?>...</td>
                                <td>
                                    <span class="badge badge-<?php echo $row['status'] == 'open' ? 'open' : ($row['status'] == 'under_review' ? 'review' : 'resolved'); ?>">
                                        <?php echo ucfirst($row['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo timeAgo($row['created_at']); ?></td>
                                <td><a href="?view=<?php echo $row['id']; ?>" class="btn-sm" onclick="event.stopPropagation()">Review</a></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty-state" style="text-align: center; padding: 60px; color: #94a3b8;">
                    <i class="fas fa-gavel" style="font-size: 48px; margin-bottom: 16px; display: block;"></i>
                    <p>No disputes found</p>
                </div>
            <?php endif; ?>
        </div>
        
        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <a href="?page=<?php echo $i; ?>&status=<?php echo urlencode($status); ?>" class="<?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php
$content = ob_get_clean();
include 'layout.php';
?>

BRS/admin/escrow_management.php

<?php
// admin/escrow_management.php - Complete Admin Escrow Dashboard

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/escrow_functions.php';

if (!isLoggedIn() || $_SESSION['user_role'] != 'admin') {
    header('Location: /broker_system/auth/login.php');
    exit;
}

$page_title = 'Escrow Management';
ob_start();

$conn = getDbConnection();
$message = '';
$error = '';

// Handle admin actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $transaction_id = intval($_POST['transaction_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    
    if ($action === 'release') {
        $result = adminReleasePayment($conn, $transaction_id, $_SESSION['user_id'], $_POST['release_notes'] ?? '');
        if ($result['success']) {
            $message = "✓ Payment released successfully!";
        } else {
            $error = $result['error'];
        }
    }
    
    if ($action === 'freeze') {
        adminFreezeTransaction($conn, $transaction_id, $_SESSION['user_id'], $_POST['freeze_reason'] ?? '');
        $message = "❄️ Transaction frozen successfully.";
    }
    
    if ($action === 'unfreeze') {
        adminUnfreezeTransaction($conn, $transaction_id, $_SESSION['user_id']);
        $message = "🔥 Transaction unfrozen successfully.";
    }
    
    if ($action === 'refund') {
        $result = refundEscrowPayment($conn, $transaction_id, $_SESSION['user_id'], $_POST['refund_notes'] ?? '');
        if ($result['success']) {
            $message = "💰 Refund processed successfully.";
        } else {
            $error = $result['error'];
        }
    }
}

// Process auto-release queue
$auto_released = processAutoReleaseQueue($conn);

// Get escrow summary
$summary = getEscrowSummary($conn);

// Get all escrow transactions
$escrow_transactions = $conn->query("
    SELECT t.*, l.title, l.type, 
           u1.full_name as buyer_name, u2.full_name as seller_name,
           ea.amount as escrow_amount,
           eq.scheduled_release_date,
           (SELECT COUNT(*) FROM transaction_timeline tt WHERE tt.transaction_id = t.id) as timeline_count
    FROM transactions t
    JOIN listings l ON t.listing_id = l.id
    JOIN users u1 ON t.buyer_id = u1.id
    JOIN users u2 ON t.seller_id = u2.id
    LEFT JOIN escrow_accounts ea ON t.id = ea.transaction_id AND ea.status = 'held'
    LEFT JOIN escrow_release_queue eq ON t.id = eq.transaction_id AND eq.status = 'pending'
    WHERE t.escrow_status IN ('active', 'released') OR t.status IN ('escrow_active', 'completed')
    ORDER BY t.created_at DESC
");

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Escrow Management</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f0f2f5; }
        
        .admin-container { max-width: 1400px; margin: 0 auto; padding: 20px; }
        
        .header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 24px;
            padding: 28px;
            margin-bottom: 28px;
            color: white;
        }
        .header h1 { font-size: 28px; font-weight: 700; margin-bottom: 8px; }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 28px;
        }
        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 24px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            border: 1px solid #e2e8f0;
        }
        .stat-value { font-size: 28px; font-weight: 700; color: #0f172a; }
        .stat-label { font-size: 12px; color: #64748b; margin-top: 4px; }
        
        .escrow-card {
            background: white;
            border-radius: 24px;
            margin-bottom: 20px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            border: 1px solid #e2e8f0;
        }
        .escrow-header {
            padding: 20px 24px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }
        .escrow-body { padding: 20px 24px; }
        
        .badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
        }
        .badge-active { background: #dbeafe; color: #1e40af; }
        .badge-frozen { background: #fee2e2; color: #dc2626; }
        .badge-completed { background: #d1fae5; color: #059669; }
        .badge-pending { background: #fed7aa; color: #ea580c; }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #e2e8f0;
        }
        .info-row:last-child { border-bottom: none; }
        
        .btn-group { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 16px; }
        .btn {
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-primary { background: #667eea; color: white; }
        .btn-success { background: #10b981; color: white; }
        .btn-danger { background: #ef4444; color: white; }
        .btn-warning { background: #f59e0b; color: white; }
        .btn-outline { background: transparent; border: 1px solid #e2e8f0; color: #64748b; }
        
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
            width: 500px;
            max-width: 90%;
        }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; margin-bottom: 6px; font-weight: 600; }
        .form-group input, .form-group textarea { width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 10px; }
        
        .alert { padding: 12px 16px; border-radius: 12px; margin-bottom: 20px; }
        .alert-success { background: #d1fae5; color: #059669; }
        .alert-error { background: #fee2e2; color: #dc2626; }
        
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .escrow-header { flex-direction: column; align-items: flex-start; }
            .btn-group { flex-direction: column; }
        }
    </style>
</head>
<body>
<div class="admin-container">
    <div class="header">
        <h1><i class="fas fa-shield-alt"></i> Escrow Management Dashboard</h1>
        <p>Monitor and manage all escrow transactions</p>
    </div>
    
    <?php if ($auto_released > 0): ?>
        <div class="alert alert-success">✓ <?php echo $auto_released; ?> payment(s) auto-released.</div>
    <?php endif; ?>
    
    <?php if ($message): ?>
        <div class="alert alert-success">✓ <?php echo $message; ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-error">⚠️ <?php echo $error; ?></div>
    <?php endif; ?>
    
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?php echo formatMoney($summary['total_held']); ?></div>
            <div class="stat-label">Total Escrow Held</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo formatMoney($summary['total_released']); ?></div>
            <div class="stat-label">Total Released</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $summary['active_transactions']; ?></div>
            <div class="stat-label">Active Escrow</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $summary['pending_release']; ?></div>
            <div class="stat-label">Pending Release</div>
        </div>
    </div>
    
    <h2 style="margin-bottom: 20px;">All Escrow Transactions</h2>
    
    <?php while($txn = $escrow_transactions->fetch_assoc()): ?>
        <div class="escrow-card">
            <div class="escrow-header">
                <div>
                    <strong>#<?php echo $txn['id']; ?></strong> - <?php echo htmlspecialchars($txn['title']); ?>
                    <span class="badge <?php 
                        if ($txn['admin_frozen']) echo 'badge-frozen';
                        elseif ($txn['status'] == 'completed') echo 'badge-completed';
                        elseif ($txn['escrow_status'] == 'active') echo 'badge-active';
                        else echo 'badge-pending';
                    ?>" style="margin-left: 10px;">
                        <?php 
                        if ($txn['admin_frozen']) echo '❄️ Frozen';
                        elseif ($txn['status'] == 'completed') echo '✓ Completed';
                        elseif ($txn['escrow_status'] == 'active') echo '💰 Escrow Active';
                        else echo '⏳ Pending';
                        ?>
                    </span>
                </div>
                <div><strong><?php echo formatMoney($txn['total_amount']); ?></strong></div>
            </div>
            <div class="escrow-body">
                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 16px;">
                    <div><small>Buyer:</small><br><strong><?php echo htmlspecialchars($txn['buyer_name']); ?></strong></div>
                    <div><small>Seller:</small><br><strong><?php echo htmlspecialchars($txn['seller_name']); ?></strong></div>
                    <div><small>Escrow Amount:</small><br><strong><?php echo formatMoney($txn['escrow_amount'] ?? 0); ?></strong></div>
                </div>
                
                <div class="info-row">
                    <span>Delivery Status:</span>
                    <span><?php echo ucfirst($txn['delivery_status'] ?? 'pending'); ?></span>
                </div>
                <div class="info-row">
                    <span>Created:</span>
                    <span><?php echo date('M d, Y H:i', strtotime($txn['created_at'])); ?></span>
                </div>
                <?php if ($txn['scheduled_release_date']): ?>
                <div class="info-row">
                    <span>Auto-Release:</span>
                    <span><?php echo date('M d, Y', strtotime($txn['scheduled_release_date'])); ?></span>
                </div>
                <?php endif; ?>
                
                <div class="btn-group">
                    <?php if ($txn['escrow_status'] == 'active' && !$txn['admin_frozen']): ?>
                        <button onclick="openReleaseModal(<?php echo $txn['id']; ?>)" class="btn btn-success">
                            <i class="fas fa-money-bill-wave"></i> Release Payment
                        </button>
                        <button onclick="openFreezeModal(<?php echo $txn['id']; ?>)" class="btn btn-warning">
                            <i class="fas fa-ice-cream"></i> Freeze
                        </button>
                        <button onclick="openRefundModal(<?php echo $txn['id']; ?>)" class="btn btn-danger">
                            <i class="fas fa-undo"></i> Refund Buyer
                        </button>
                    <?php elseif ($txn['admin_frozen']): ?>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="transaction_id" value="<?php echo $txn['id']; ?>">
                            <input type="hidden" name="action" value="unfreeze">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-fire"></i> Unfreeze
                            </button>
                        </form>
                    <?php endif; ?>
                    
                    <a href="/broker_system/user/transaction.php?id=<?php echo $txn['id']; ?>" target="_blank" class="btn btn-outline">
                        <i class="fas fa-eye"></i> View Details
                    </a>
                </div>
            </div>
        </div>
    <?php endwhile; ?>
    
    <?php if ($escrow_transactions->num_rows == 0): ?>
        <div style="text-align: center; padding: 60px; background: white; border-radius: 20px;">
            <i class="fas fa-shield-alt" style="font-size: 64px; color: #cbd5e1; margin-bottom: 16px; display: block;"></i>
            <h3>No Escrow Transactions</h3>
            <p>No escrow transactions found in the system.</p>
        </div>
    <?php endif; ?>
</div>

<!-- Release Modal -->
<div id="releaseModal" class="modal">
    <div class="modal-content">
        <h3 style="margin-bottom: 16px;"><i class="fas fa-money-bill-wave"></i> Release Payment</h3>
        <form method="POST">
            <input type="hidden" name="transaction_id" id="releaseTransactionId">
            <input type="hidden" name="action" value="release">
            <div class="form-group">
                <label>Release Notes</label>
                <textarea name="release_notes" rows="3" placeholder="Reason for manual release..."></textarea>
            </div>
            <div class="btn-group">
                <button type="submit" class="btn btn-success">Confirm Release</button>
                <button type="button" onclick="closeReleaseModal()" class="btn btn-outline">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Freeze Modal -->
<div id="freezeModal" class="modal">
    <div class="modal-content">
        <h3 style="margin-bottom: 16px;"><i class="fas fa-ice-cream"></i> Freeze Transaction</h3>
        <form method="POST">
            <input type="hidden" name="transaction_id" id="freezeTransactionId">
            <input type="hidden" name="action" value="freeze">
            <div class="form-group">
                <label>Reason for Freezing</label>
                <textarea name="freeze_reason" rows="3" placeholder="Enter reason for freezing this transaction..." required></textarea>
            </div>
            <div class="btn-group">
                <button type="submit" class="btn btn-warning">Confirm Freeze</button>
                <button type="button" onclick="closeFreezeModal()" class="btn btn-outline">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Refund Modal -->
<div id="refundModal" class="modal">
    <div class="modal-content">
        <h3 style="margin-bottom: 16px;"><i class="fas fa-undo"></i> Refund Buyer</h3>
        <form method="POST">
            <input type="hidden" name="transaction_id" id="refundTransactionId">
            <input type="hidden" name="action" value="refund">
            <div class="form-group">
                <label>Refund Notes</label>
                <textarea name="refund_notes" rows="3" placeholder="Reason for refund..." required></textarea>
            </div>
            <div class="btn-group">
                <button type="submit" class="btn btn-danger">Confirm Refund</button>
                <button type="button" onclick="closeRefundModal()" class="btn btn-outline">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function openReleaseModal(id) {
    document.getElementById('releaseTransactionId').value = id;
    document.getElementById('releaseModal').style.display = 'flex';
}
function closeReleaseModal() { document.getElementById('releaseModal').style.display = 'none'; }

function openFreezeModal(id) {
    document.getElementById('freezeTransactionId').value = id;
    document.getElementById('freezeModal').style.display = 'flex';
}
function closeFreezeModal() { document.getElementById('freezeModal').style.display = 'none'; }

function openRefundModal(id) {
    document.getElementById('refundTransactionId').value = id;
    document.getElementById('refundModal').style.display = 'flex';
}
function closeRefundModal() { document.getElementById('refundModal').style.display = 'none'; }

window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
}
</script>

<?php
$content = ob_get_clean();
include 'layout.php';
?>

BRS/admin/layout.php

<?php
// admin/layout.php - Modern Professional Admin Layout with Hamburger Menu

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check authentication with unified session
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    header('Location: /broker_system/auth/login.php');
    exit;
}

// Check if user has admin or broker role
if ($_SESSION['user_role'] != 'admin' && $_SESSION['user_role'] != 'broker') {
    header('Location: /broker_system/user/dashboard.php');
    exit;
}

$admin_name = $_SESSION['user_name'];
$admin_email = $_SESSION['user_email'];
$current_page = basename($_SERVER['PHP_SELF']);

// Get unread chat count
require_once '../config/database.php';
require_once '../includes/chat_functions.php';
$conn = getDbConnection();
$unread_chat_count = getUnreadMessageCount($conn, $_SESSION['user_id']);

// Get pending approval count
$pending_approvals = $conn->query("SELECT COUNT(*) as count FROM listings WHERE approval_status = 'pending'")->fetch_assoc()['count'];
$pending_disputes = $conn->query("SELECT COUNT(*) as count FROM disputes WHERE status IN ('open', 'under_review')")->fetch_assoc()['count'];
$pending_withdrawals = $conn->query("SELECT COUNT(*) as count FROM withdrawal_requests WHERE status = 'pending'")->fetch_assoc()['count'];

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title><?php echo $page_title ?? 'Admin Panel'; ?> - Ethio Brokerplace</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: #f5f7fa;
            overflow-x: hidden;
        }
        
        /* ============================================
           MOBILE MENU TOGGLE BUTTON (HAMBURGER)
        ============================================ */
        .mobile-menu-toggle {
            display: none;
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1060;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            width: 45px;
            height: 45px;
            border-radius: 12px;
            border: none;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(102,126,234,0.3);
            transition: all 0.3s ease;
        }
        
        .mobile-menu-toggle:hover {
            transform: scale(1.05);
            box-shadow: 0 6px 16px rgba(102,126,234,0.4);
        }
        
        /* Sidebar Overlay */
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            backdrop-filter: blur(2px);
            z-index: 1040;
        }
        
        .sidebar-overlay.active {
            display: block;
        }
        
        /* ============================================
           SIDEBAR STYLES
        ============================================ */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 280px;
            height: 100%;
            background: linear-gradient(180deg, #0f172a 0%, #0f172a 100%);
            color: #e2e8f0;
            transition: all 0.3s ease;
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
        .sidebar { scrollbar-width: thin; scrollbar-color: rgba(255,255,255,0.2) rgba(255,255,255,0.05); }
        
        /* Collapsed Sidebar (Desktop) */
        .sidebar.collapsed { width: 88px; }
        .sidebar.collapsed .logo-text,
        .sidebar.collapsed .menu-label,
        .sidebar.collapsed .profile-info,
        .sidebar.collapsed .section-header { display: none; }
        .sidebar.collapsed .menu-item { justify-content: center; padding: 12px; }
        .sidebar.collapsed .menu-item i { margin-right: 0; font-size: 20px; }
        .sidebar.collapsed .logo { justify-content: center; }
        .sidebar.collapsed .badge-count { position: absolute; top: 5px; right: 5px; }
        
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
        }
        
        .logo-text {
            font-size: 18px;
            font-weight: 700;
            background: linear-gradient(135deg, #667eea, #764ba2);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .collapse-btn {
            background: rgba(255,255,255,0.08);
            border: none;
            color: #94a3b8;
            width: 32px;
            height: 32px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .collapse-btn:hover {
            background: rgba(255,255,255,0.15);
            color: white;
        }
        
        /* Navigation Menu */
        .nav-menu {
            list-style: none;
            padding: 20px 16px;
        }
        
        .menu-item {
            display: flex;
            align-items: center;
            padding: 10px 14px;
            margin: 4px 0;
            border-radius: 12px;
            color: #94a3b8;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            position: relative;
        }
        
        .menu-item i {
            width: 24px;
            font-size: 18px;
            margin-right: 12px;
            flex-shrink: 0;
        }
        
        .menu-item span {
            font-size: 14px;
            font-weight: 500;
        }
        
        .menu-item:hover {
            background: rgba(255,255,255,0.08);
            color: white;
        }
        
        .menu-item.active {
            background: linear-gradient(135deg, #667eea20, #764ba220);
            color: white;
            border-left: 3px solid #667eea;
        }
        
        .badge-count {
            background: #ef4444;
            color: white;
            font-size: 10px;
            font-weight: 600;
            padding: 2px 6px;
            border-radius: 20px;
            margin-left: auto;
            min-width: 18px;
            text-align: center;
        }
        
        .section-header {
            padding: 12px 16px 6px;
            margin-top: 12px;
            color: #475569;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
        }
        
        /* Sidebar Footer */
        .sidebar-footer {
            position: sticky;
            bottom: 0;
            left: 0;
            right: 0;
            padding: 16px;
            border-top: 1px solid rgba(255,255,255,0.08);
            background: #0f172a;
            margin-top: 20px;
        }
        
        .profile-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 12px;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            color: #e2e8f0;
        }
        
        .profile-item:hover {
            background: rgba(255,255,255,0.08);
        }
        
        .profile-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 16px;
            flex-shrink: 0;
        }
        
        .profile-info {
            flex: 1;
            min-width: 0;
        }
        
        .profile-name {
            font-size: 14px;
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .profile-email {
            font-size: 11px;
            color: #64748b;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        /* ============================================
           MAIN CONTENT STYLES
        ============================================ */
        .main-content {
            margin-left: 280px;
            transition: all 0.3s ease;
            min-height: 100vh;
        }
        
        .main-content.expanded {
            margin-left: 88px;
        }
        
        /* Top Bar */
        .top-bar {
            background: white;
            padding: 16px 28px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 99;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            border-bottom: 1px solid #e2e8f0;
        }
        
        .page-title {
            font-size: 24px;
            font-weight: 700;
            color: #0f172a;
            letter-spacing: -0.3px;
        }
        
        .admin-info {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .admin-badge {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 14px;
            background: #f1f5f9;
            border-radius: 30px;
            font-size: 13px;
            font-weight: 500;
            color: #475569;
        }
        
        .admin-badge i {
            color: #667eea;
            font-size: 14px;
        }
        
        .logout-btn {
            padding: 8px 20px;
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
            border-radius: 30px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.3s;
        }
        
        .logout-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(239,68,68,0.3);
        }
        
        /* Container */
        .container {
            padding: 28px;
        }
        
        /* ============================================
           RESPONSIVE BREAKPOINTS
        ============================================ */
        
        /* Desktop and Tablet (above 768px) - No hamburger needed */
        @media (min-width: 769px) {
            .mobile-menu-toggle {
                display: none !important;
            }
        }
        
        /* Tablet (768px - 1024px) - Collapsed sidebar */
        @media (max-width: 1024px) and (min-width: 769px) {
            .sidebar { 
                width: 88px; 
            }
            .sidebar .logo-text,
            .sidebar .menu-label,
            .sidebar .profile-info,
            .sidebar .section-header { display: none; }
            .sidebar .menu-item { justify-content: center; padding: 12px; }
            .sidebar .menu-item i { margin-right: 0; font-size: 20px; }
            .main-content { margin-left: 88px; }
        }
        
        /* Mobile (below 768px) - Hidden sidebar with hamburger */
        @media (max-width: 768px) {
            .mobile-menu-toggle {
                display: flex;
                align-items: center;
                justify-content: center;
            }
            
            .sidebar {
                transform: translateX(-100%);
                width: 280px;
                transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
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
                padding: 10px 14px;
            }
            
            .sidebar.mobile-open .menu-item i {
                margin-right: 12px;
                font-size: 18px;
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .top-bar {
                padding: 12px 20px;
                padding-left: 80px;
            }
            
            .page-title {
                font-size: 18px;
            }
            
            .container {
                padding: 16px;
            }
            
            .admin-badge span {
                display: none;
            }
            
            .admin-badge {
                padding: 8px 12px;
            }
            
            .logout-btn span {
                display: none;
            }
            
            .logout-btn {
                padding: 8px 12px;
            }
        }
        
        /* Small Mobile (below 480px) */
        @media (max-width: 480px) {
            .top-bar {
                padding: 10px 16px;
                padding-left: 70px;
            }
            
            .page-title {
                font-size: 16px;
            }
            
            .container {
                padding: 12px;
            }
        }
    </style>
</head>
<body>

    <!-- Mobile Menu Toggle (Hamburger Button) -->
    <button class="mobile-menu-toggle" id="mobileMenuToggle">
        <i class="fas fa-bars"></i>
    </button>
    
    <!-- Sidebar Overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

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
            <!-- Main Navigation -->
            <a href="dashboard.php" class="menu-item <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
                <i class="fas fa-chart-line"></i>
                <span class="menu-label">Dashboard</span>
            </a>
            <a href="users.php" class="menu-item <?php echo $current_page == 'users.php' ? 'active' : ''; ?>">
                <i class="fas fa-users"></i>
                <span class="menu-label">Users</span>
            </a>
            <a href="transactions.php" class="menu-item <?php echo $current_page == 'transactions.php' ? 'active' : ''; ?>">
                <i class="fas fa-exchange-alt"></i>
                <span class="menu-label">Transactions</span>
            </a>
            <a href="chat.php" class="menu-item <?php echo $current_page == 'chat.php' ? 'active' : ''; ?>">
                <i class="fas fa-comments"></i>
                <span class="menu-label">Messages</span>
                <?php if ($unread_chat_count > 0): ?>
                    <span class="badge-count"><?php echo $unread_chat_count; ?></span>
                <?php endif; ?>
            </a>
            
            <!-- Management Section -->
            <div class="section-header">Management</div>
            <a href="approve_listings.php" class="menu-item <?php echo $current_page == 'approve_listings.php' ? 'active' : ''; ?>">
                <i class="fas fa-check-double"></i>
                <span class="menu-label">Approve Listings</span>
                <?php if ($pending_approvals > 0): ?>
                    <span class="badge-count"><?php echo $pending_approvals; ?></span>
                <?php endif; ?>
            </a>
            <a href="disputes.php" class="menu-item <?php echo $current_page == 'disputes.php' ? 'active' : ''; ?>">
                <i class="fas fa-gavel"></i>
                <span class="menu-label">Disputes</span>
                <?php if ($pending_disputes > 0): ?>
                    <span class="badge-count"><?php echo $pending_disputes; ?></span>
                <?php endif; ?>
            </a>
            <a href="withdrawals.php" class="menu-item <?php echo $current_page == 'withdrawals.php' ? 'active' : ''; ?>">
                <i class="fas fa-money-bill-wave"></i>
                <span class="menu-label">Withdrawals</span>
                <?php if ($pending_withdrawals > 0): ?>
                    <span class="badge-count"><?php echo $pending_withdrawals; ?></span>
                <?php endif; ?>
            </a>
            
            <!-- Settings -->
            <div class="section-header">Settings</div>
            <a href="settings.php" class="menu-item <?php echo $current_page == 'settings.php' ? 'active' : ''; ?>">
                <i class="fas fa-cog"></i>
                <span class="menu-label">Settings</span>
            </a>
        </ul>
        
        <div class="sidebar-footer">
            <div class="profile-item">
                <div class="profile-avatar"><?php echo strtoupper(substr($admin_name, 0, 1)); ?></div>
                <div class="profile-info">
                    <div class="profile-name"><?php echo htmlspecialchars($admin_name); ?></div>
                    <div class="profile-email"><?php echo htmlspecialchars($admin_email); ?></div>
                </div>
            </div>
            <a href="../auth/logout.php" class="menu-item" style="margin-top: 8px;">
                <i class="fas fa-sign-out-alt logout-icon"></i>
                <span class="menu-label">Logout</span>
            </a>
        </div>
    </div>
    
    <!-- MAIN CONTENT -->
    <div class="main-content" id="mainContent">
        <div class="top-bar">
            <h1 class="page-title"><?php echo $page_title ?? 'Dashboard'; ?></h1>
            <div class="admin-info">
                <div class="admin-badge">
                    <i class="fas fa-user-shield"></i>
                    <span>Admin</span>
                </div>
                <a href="../auth/logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i> <span>Logout</span>
                </a>
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
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const sidebarOverlay = document.getElementById('sidebarOverlay');
        
        // Sidebar collapse functionality (desktop)
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
        
        // Load saved sidebar state (desktop only)
        if (window.innerWidth > 768) {
            if (localStorage.getItem('sidebarCollapsed') === 'true') {
                sidebar.classList.add('collapsed');
                mainContent.classList.add('expanded');
                if (collapseBtn) {
                    const icon = collapseBtn.querySelector('i');
                    icon.classList.remove('fa-chevron-left');
                    icon.classList.add('fa-chevron-right');
                }
            }
        }
        
        // Mobile sidebar toggle
        function openMobileSidebar() {
            sidebar.classList.add('mobile-open');
            sidebarOverlay.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        
        function closeMobileSidebar() {
            sidebar.classList.remove('mobile-open');
            sidebarOverlay.classList.remove('active');
            document.body.style.overflow = '';
        }
        
        if (mobileMenuToggle) {
            mobileMenuToggle.addEventListener('click', openMobileSidebar);
        }
        
        if (sidebarOverlay) {
            sidebarOverlay.addEventListener('click', closeMobileSidebar);
        }
        
        // Close sidebar on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && sidebar.classList.contains('mobile-open')) {
                closeMobileSidebar();
            }
        });
        
        // Close sidebar when window is resized above mobile breakpoint
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768) {
                closeMobileSidebar();
                document.body.style.overflow = '';
            }
        });
    </script>
</body>
</html>

BRS/admin/login.php

<?php
// admin/login.php - Redirect to unified login page

header('Location: /broker_system/auth/login.php');
exit;
?>

BRS/admin/logout.php

<?php
// admin/logout.php - Redirect to unified logout

header('Location: /broker_system/auth/logout.php');
exit;
?>

BRS/admin/messages.php

<?php
// admin/messages.php - User Messages

$page_title = 'User Messages';
ob_start();

require_once '../config/database.php';
require_once '../includes/functions.php';

$conn = getDbConnection();
$message = '';

// Handle sending reply
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_reply'])) {
    $messageId = intval($_POST['message_id']);
    $replyText = $conn->real_escape_string($_POST['reply_text']);
    $userId = intval($_POST['user_id']);
    
    $stmt = $conn->prepare("INSERT INTO messages (from_user_id, to_admin, subject, message, is_replied) VALUES (?, 0, ?, ?, 1)");
    $adminId = $_SESSION['admin_id'] ?? 1;
    $subject = "RE: Admin Response";
    $stmt->bind_param("iss", $adminId, $subject, $replyText);
    
    if ($stmt->execute()) {
        $conn->query("UPDATE messages SET is_replied = 1 WHERE id = $messageId");
        $conn->query("INSERT INTO notifications (user_id, title, message) VALUES ($userId, 'Admin Response', 'Admin replied to your message')");
        $message = "Reply sent successfully";
    }
}

$status = $_GET['status'] ?? 'all';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$where = "m.to_admin = 1";
if ($status == 'unread') $where .= " AND m.is_replied = 0";
if ($status == 'replied') $where .= " AND m.is_replied = 1";

$sql = "SELECT m.*, u.full_name, u.email, u.phone 
        FROM messages m
        JOIN users u ON m.from_user_id = u.id
        WHERE $where
        ORDER BY m.created_at DESC
        LIMIT $offset, $limit";
$messages = $conn->query($sql);

$total = $conn->query("SELECT COUNT(*) as count FROM messages WHERE $where")->fetch_assoc()['count'];
$totalPages = ceil($total / $limit);

$viewMessage = null;
if (isset($_GET['view'])) {
    $viewId = intval($_GET['view']);
    $viewMessage = $conn->query("
        SELECT m.*, u.full_name, u.email, u.phone, u.id as user_id
        FROM messages m
        JOIN users u ON m.from_user_id = u.id
        WHERE m.id = $viewId
    ")->fetch_assoc();
}

$stats = [
    'total' => $conn->query("SELECT COUNT(*) as count FROM messages WHERE to_admin = 1")->fetch_assoc()['count'],
    'unread' => $conn->query("SELECT COUNT(*) as count FROM messages WHERE to_admin = 1 AND is_replied = 0")->fetch_assoc()['count'],
    'replied' => $conn->query("SELECT COUNT(*) as count FROM messages WHERE to_admin = 1 AND is_replied = 1")->fetch_assoc()['count'],
];

$conn->close();
?>

<style>
    .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 24px; }
    .stat-card { background: white; border-radius: 20px; padding: 20px; text-align: center; }
    .stat-value { font-size: 32px; font-weight: 700; }
    .tabs { display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; }
    .tab { padding: 8px 20px; background: white; border-radius: 30px; text-decoration: none; color: #64748b; }
    .tab.active { background: linear-gradient(135deg, #667eea, #764ba2); color: white; }
    .message-view { background: #f8fafc; padding: 20px; border-radius: 16px; margin-bottom: 20px; }
    .reply-form textarea { width: 100%; padding: 12px; border: 1px solid #e2e8f0; border-radius: 12px; margin-bottom: 12px; font-family: inherit; }
    .btn-send { background: #667eea; color: white; padding: 10px 24px; border: none; border-radius: 40px; cursor: pointer; }
    .unread-row { font-weight: 600; background: #fef3c7; }
    .pagination { display: flex; justify-content: center; gap: 8px; margin-top: 20px; }
    .pagination a, .pagination span { padding: 8px 12px; background: white; border: 1px solid #e2e8f0; border-radius: 8px; text-decoration: none; color: #333; }
    .pagination .active { background: #667eea; color: white; }
</style>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-value"><?php echo $stats['total']; ?></div><div class="stat-label">Total Messages</div></div>
    <div class="stat-card"><div class="stat-value"><?php echo $stats['unread']; ?></div><div class="stat-label">Unread</div></div>
    <div class="stat-card"><div class="stat-value"><?php echo $stats['replied']; ?></div><div class="stat-label">Replied</div></div>
</div>

<div class="tabs">
    <a href="?status=all" class="tab <?php echo $status == 'all' ? 'active' : ''; ?>">All</a>
    <a href="?status=unread" class="tab <?php echo $status == 'unread' ? 'active' : ''; ?>">Unread</a>
    <a href="?status=replied" class="tab <?php echo $status == 'replied' ? 'active' : ''; ?>">Replied</a>
</div>

<?php if ($viewMessage): ?>
    <div class="card">
        <div class="card-header">
            <h2>Message from <?php echo htmlspecialchars($viewMessage['full_name']); ?></h2>
            <a href="messages.php?status=<?php echo $status; ?>" class="btn-sm btn-primary">← Back</a>
        </div>
        <div class="message-view">
            <p><strong>From:</strong> <?php echo htmlspecialchars($viewMessage['full_name']); ?> (<?php echo htmlspecialchars($viewMessage['email']); ?>)</p>
            <p><strong>Subject:</strong> <?php echo htmlspecialchars($viewMessage['subject']); ?></p>
            <p><strong>Date:</strong> <?php echo date('F d, Y H:i', strtotime($viewMessage['created_at'])); ?></p>
            <div style="margin-top: 16px; padding: 16px; background: white; border-radius: 12px;">
                <?php echo nl2br(htmlspecialchars($viewMessage['message'])); ?>
            </div>
        </div>
        <div class="reply-form">
            <h3>Send Reply</h3>
            <form method="POST">
                <input type="hidden" name="message_id" value="<?php echo $viewMessage['id']; ?>">
                <input type="hidden" name="user_id" value="<?php echo $viewMessage['user_id']; ?>">
                <textarea name="reply_text" rows="4" placeholder="Type your reply here..." required></textarea>
                <button type="submit" name="send_reply" class="btn-send"><i class="fas fa-paper-plane"></i> Send Reply</button>
            </form>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <div class="card-header"><h2><i class="fas fa-envelope"></i> All Messages</h2></div>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>From</th><th>Subject</th><th>Message</th><th>Status</th><th>Date</th><th>Action</th></tr></thead>
                <tbody>
                    <?php while($row = $messages->fetch_assoc()): ?>
                    <tr class="<?php echo !$row['is_replied'] ? 'unread-row' : ''; ?>" onclick="location.href='?view=<?php echo $row['id']; ?>&status=<?php echo $status; ?>'" style="cursor:pointer;">
                        <td><strong><?php echo htmlspecialchars($row['full_name']); ?></strong><br><small><?php echo htmlspecialchars($row['email']); ?></small></td>
                        <td><?php echo htmlspecialchars(substr($row['subject'], 0, 40)); ?></td>
                        <td><?php echo htmlspecialchars(substr($row['message'], 0, 60)); ?>...</td>
                        <td><?php echo $row['is_replied'] ? '<span class="badge badge-success">Replied</span>' : '<span class="badge badge-warning">Unread</span>'; ?></td>
                        <td><?php echo timeAgo($row['created_at']); ?></td>
                        <td><a href="?view=<?php echo $row['id']; ?>&status=<?php echo $status; ?>" class="btn-sm btn-primary" onclick="event.stopPropagation()">Reply</a></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <a href="?page=<?php echo $i; ?>&status=<?php echo urlencode($status); ?>" class="<?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php
$content = ob_get_clean();
include 'layout.php';
?>

BRS/admin/negotiations.php



BRS/admin/payments.php

<?php
// admin/payments.php - Payment Management

$page_title = 'Payments Overview';
ob_start();

require_once '../config/database.php';
require_once '../includes/functions.php';

$conn = getDbConnection();

$sql = "SELECT p.*, t.payment_code_5digit, t.status as transaction_status, u.full_name as user_name,
        CASE WHEN p.type = 'deposit_buyer' THEN 'Buyer Deposit'
             WHEN p.type = 'deposit_seller' THEN 'Seller Deposit'
             WHEN p.type = 'commission' THEN 'System Commission'
             WHEN p.type = 'remaining_balance' THEN 'Remaining Balance'
             WHEN p.type = 'release_to_seller' THEN 'Released to Seller'
             ELSE p.type END as payment_type_name
        FROM payments p
        JOIN transactions t ON p.transaction_id = t.id
        JOIN users u ON p.user_id = u.id
        ORDER BY p.created_at DESC
        LIMIT 50";
$payments = $conn->query($sql);

$stats = [
    'total' => $conn->query("SELECT SUM(amount) as total FROM payments WHERE status = 'confirmed'")->fetch_assoc()['total'] ?? 0,
    'escrow' => $conn->query("SELECT SUM(escrow_held) as total FROM transactions WHERE status NOT IN ('completed', 'cancelled')")->fetch_assoc()['total'] ?? 0,
    'commission' => $conn->query("SELECT SUM(amount) as total FROM payments WHERE type = 'commission' AND status = 'confirmed'")->fetch_assoc()['total'] ?? 0,
];

$conn->close();
?>

<style>
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 20px;
        margin-bottom: 28px;
    }
    
    .stat-card {
        background: white;
        border-radius: 20px;
        padding: 24px;
        text-align: center;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        transition: all 0.3s;
    }
    
    .stat-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 24px -8px rgba(0,0,0,0.15);
    }
    
    .stat-value {
        font-size: 28px;
        font-weight: 700;
        color: #0f172a;
    }
    
    .stat-label {
        font-size: 13px;
        color: #64748b;
        margin-top: 6px;
    }
    
    .card {
        background: white;
        border-radius: 20px;
        padding: 24px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    
    .card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        padding-bottom: 16px;
        border-bottom: 2px solid #f1f5f9;
    }
    
    .card-header h2 {
        font-size: 18px;
        font-weight: 600;
        color: #0f172a;
    }
    
    .table-wrapper {
        overflow-x: auto;
    }
    
    table {
        width: 100%;
        border-collapse: collapse;
    }
    
    th, td {
        padding: 12px;
        text-align: left;
        border-bottom: 1px solid #f1f5f9;
        font-size: 13px;
    }
    
    th {
        font-weight: 600;
        color: #64748b;
        background: #fafbfc;
    }
    
    .badge {
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 500;
        display: inline-block;
    }
    
    .badge-success { background: #d1fae5; color: #059669; }
    
    code {
        background: #f1f5f9;
        padding: 2px 6px;
        border-radius: 6px;
        font-size: 11px;
    }
    
    @media (max-width: 768px) {
        .stats-grid { grid-template-columns: 1fr; }
        th, td { padding: 8px; font-size: 12px; }
    }
</style>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-value"><?php echo formatMoney($stats['total']); ?></div>
        <div class="stat-label">Total Processed</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo formatMoney($stats['escrow']); ?></div>
        <div class="stat-label">Escrow Held</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo formatMoney($stats['commission']); ?></div>
        <div class="stat-label">Commission Earned</div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2><i class="fas fa-credit-card"></i> Recent Payments</h2>
        <span><?php echo $payments->num_rows; ?> payments</span>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>User</th>
                    <th>Type</th>
                    <th>Amount</th>
                    <th>Transaction</th>
                    <th>Status</th>
                    <th>Telebirr Code</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                <?php while($row = $payments->fetch_assoc()): ?>
                <tr>
                    <td>#<?php echo $row['id']; ?></td>
                    <td><?php echo htmlspecialchars($row['user_name']); ?></td>
                    <td><?php echo $row['payment_type_name']; ?></td>
                    <td><strong><?php echo formatMoney($row['amount']); ?></strong></td>
                    <td>#<?php echo $row['transaction_id']; ?></td>
                    <td><span class="badge badge-success"><?php echo $row['status']; ?></span></td>
                    <td><code><?php echo $row['telebirr_code_5digit'] ?? '-'; ?></code></td>
                    <td><?php echo date('M d, H:i', strtotime($row['created_at'])); ?></td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
$content = ob_get_clean();
include 'layout.php';
?>

BRS/admin/settings.php

<?php
// admin/settings.php - System Settings (Redesigned)

$page_title = 'System Settings';
ob_start();

require_once '../config/database.php';
require_once '../config/config.php';
require_once '../includes/functions.php';

$conn = getDbConnection();
$message = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_settings'])) {
        updateSetting('deposit_percent', intval($_POST['deposit_percent']));
        updateSetting('commission_percent', intval($_POST['commission_percent']));
        updateSetting('escrow_days', intval($_POST['escrow_days']));
        updateSetting('min_withdrawal', floatval($_POST['min_withdrawal']));
        updateSetting('max_withdrawal', floatval($_POST['max_withdrawal']));
        $message = "Settings saved successfully";
    }
}

$depositPercent = getSetting('deposit_percent', 30);
$commissionPercent = getSetting('commission_percent', 15);
$escrowDays = getSetting('escrow_days', 14);
$minWithdrawal = getSetting('min_withdrawal', 100);
$maxWithdrawal = getSetting('max_withdrawal', 100000);

$conn->close();
?>

<style>
    :root {
        --primary: #4f46e5;
        --primary-dark: #4338ca;
        --primary-soft: #eef2ff;
        --success: #10b981;
        --warning: #f59e0b;
        --gray-50: #f9fafb;
        --gray-100: #f3f4f6;
        --gray-200: #e5e7eb;
        --gray-600: #4b5563;
        --gray-700: #374151;
        --gray-800: #1f2937;
        --gray-900: #111827;
        --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
        --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1);
        --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1);
        --radius-lg: 1rem;
        --radius-xl: 1.5rem;
        --radius-2xl: 2rem;
    }

    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        background: linear-gradient(145deg, #f8fafc 0%, #f1f5f9 100%);
        font-family: 'Inter', system-ui, -apple-system, sans-serif;
    }

    /* Main content wrapper - assumes layout.php provides container */
    .settings-wrapper {
        max-width: 1400px;
        margin: 0 auto;
        padding: 1.5rem;
    }

    /* Header area */
    .settings-header {
        margin-bottom: 2rem;
    }

    .settings-header h1 {
        font-size: 1.875rem;
        font-weight: 700;
        background: linear-gradient(135deg, #0f172a, #334155);
        -webkit-background-clip: text;
        background-clip: text;
        color: transparent;
        letter-spacing: -0.02em;
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .settings-header h1 i {
        background: linear-gradient(135deg, #4f46e5, #7c3aed);
        -webkit-background-clip: text;
        background-clip: text;
        color: transparent;
        font-size: 1.8rem;
    }

    .settings-header p {
        color: #475569;
        margin-top: 0.5rem;
        font-size: 0.9rem;
    }

    /* Alert toasts */
    .alert-toast {
        background: white;
        border-radius: var(--radius-lg);
        padding: 1rem 1.5rem;
        margin-bottom: 1.5rem;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        box-shadow: var(--shadow-lg);
        border-left: 5px solid var(--success);
        animation: slideIn 0.3s ease;
    }

    .alert-toast.success {
        border-left-color: var(--success);
        background: #ecfdf5;
    }

    .alert-toast i {
        font-size: 1.25rem;
        color: var(--success);
    }

    .alert-toast span {
        color: #065f46;
        font-weight: 500;
        font-size: 0.9rem;
    }

    @keyframes slideIn {
        from {
            opacity: 0;
            transform: translateY(-10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    /* Two column layout */
    .settings-grid {
        display: grid;
        grid-template-columns: 1fr 380px;
        gap: 1.75rem;
        align-items: start;
    }

    /* Form card */
    .form-card {
        background: white;
        border-radius: var(--radius-2xl);
        box-shadow: var(--shadow-md);
        overflow: hidden;
        transition: transform 0.2s, box-shadow 0.2s;
        border: 1px solid rgba(226, 232, 240, 0.6);
    }

    .form-header {
        padding: 1.5rem 2rem;
        background: white;
        border-bottom: 1px solid #eef2ff;
    }

    .form-header h2 {
        font-size: 1.25rem;
        font-weight: 700;
        color: var(--gray-800);
        display: flex;
        align-items: center;
        gap: 0.6rem;
    }

    .form-header h2 i {
        color: var(--primary);
        font-size: 1.3rem;
    }

    .form-body {
        padding: 1.75rem 2rem 2rem;
    }

    /* Form groups - modern */
    .setting-group {
        margin-bottom: 1.75rem;
    }

    .setting-group label {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-weight: 600;
        color: var(--gray-700);
        font-size: 0.85rem;
        margin-bottom: 0.6rem;
        letter-spacing: -0.2px;
    }

    .setting-group label i {
        color: var(--primary);
        font-size: 0.9rem;
        width: 20px;
    }

    .input-wrapper {
        position: relative;
        display: flex;
        align-items: center;
    }

    .input-wrapper input {
        width: 100%;
        padding: 0.85rem 1rem;
        border: 1.5px solid #e2e8f0;
        border-radius: 1rem;
        font-size: 0.9rem;
        transition: all 0.2s;
        background: #fefefe;
        font-weight: 500;
    }

    .input-wrapper input:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1);
        background: white;
    }

    .input-symbol {
        position: absolute;
        right: 1rem;
        color: #94a3b8;
        font-weight: 500;
        font-size: 0.85rem;
        pointer-events: none;
    }

    .setting-group small {
        display: block;
        margin-top: 0.5rem;
        font-size: 0.7rem;
        color: #64748b;
        line-height: 1.4;
        padding-left: 0.25rem;
    }

    .btn-modern {
        background: linear-gradient(105deg, var(--primary), var(--primary-dark));
        color: white;
        border: none;
        padding: 0.9rem 1.75rem;
        border-radius: 3rem;
        font-weight: 600;
        font-size: 0.9rem;
        cursor: pointer;
        transition: all 0.25s;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.6rem;
        width: 100%;
        margin-top: 0.75rem;
        box-shadow: 0 4px 8px rgba(79, 70, 229, 0.2);
    }

    .btn-modern:hover {
        transform: translateY(-2px);
        box-shadow: 0 12px 20px -8px rgba(79, 70, 229, 0.4);
        background: linear-gradient(105deg, #5b52f0, #5b21b6);
    }

    /* Right preview card */
    .preview-card {
        background: white;
        border-radius: var(--radius-2xl);
        box-shadow: var(--shadow-md);
        overflow: hidden;
        position: sticky;
        top: 1.5rem;
        border: 1px solid rgba(226, 232, 240, 0.8);
        transition: all 0.2s;
    }

    .preview-header {
        background: #fefce8;
        padding: 1.2rem 1.5rem;
        border-bottom: 1px solid #fde68a;
    }

    .preview-header h3 {
        font-size: 1rem;
        font-weight: 700;
        color: #854d0e;
        display: flex;
        align-items: center;
        gap: 0.6rem;
    }

    .preview-header h3 i {
        color: #eab308;
        font-size: 1.2rem;
    }

    .preview-body {
        padding: 1.5rem;
    }

    .calc-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.9rem 0;
        border-bottom: 1px dashed #f1f5f9;
    }

    .calc-row:last-of-type {
        border-bottom: none;
    }

    .calc-label {
        font-size: 0.8rem;
        color: #475569;
        font-weight: 500;
    }

    .calc-value {
        font-weight: 700;
        color: #1e293b;
        background: #f8fafc;
        padding: 0.2rem 0.6rem;
        border-radius: 40px;
        font-size: 0.85rem;
    }

    .calc-total {
        background: linear-gradient(115deg, #4f46e5, #7c3aed);
        color: white;
        padding: 0.3rem 0.9rem;
        border-radius: 40px;
        font-weight: 700;
    }

    .separator {
        height: 2px;
        background: linear-gradient(to right, #e2e8f0, transparent);
        margin: 0.5rem 0 0.25rem;
    }

    .highlight-box {
        background: #f0f9ff;
        border-radius: 1rem;
        padding: 1rem;
        margin-top: 1.2rem;
        display: flex;
        align-items: center;
        gap: 0.8rem;
        border: 1px solid #bae6fd;
    }

    .highlight-box i {
        font-size: 1.3rem;
        color: #0284c7;
    }

    .highlight-box p {
        font-size: 0.7rem;
        color: #0c4a6e;
        line-height: 1.4;
        margin: 0;
    }

    .badge-step {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 20px;
        height: 20px;
        background: #e0e7ff;
        color: #4338ca;
        border-radius: 30px;
        font-size: 0.7rem;
        font-weight: bold;
        margin-right: 0.5rem;
    }

    /* Responsive */
    @media (max-width: 900px) {
        .settings-grid {
            grid-template-columns: 1fr;
        }
        .preview-card {
            position: static;
        }
        .form-body {
            padding: 1.5rem;
        }
        .settings-wrapper {
            padding: 1rem;
        }
    }

    @media (max-width: 480px) {
        .form-header h2 {
            font-size: 1.1rem;
        }
        .calc-row {
            flex-direction: column;
            align-items: flex-start;
            gap: 6px;
        }
    }
</style>

<div class="settings-wrapper">
    <div class="settings-header">
        <h1>
            <i class="fas fa-sliders-h"></i> 
            Platform Configuration
        </h1>
        <p>Fine-tune broker fees, deposit rules, withdrawal limits, and escrow logic</p>
    </div>

    <?php if ($message): ?>
    <div class="alert-toast success">
        <i class="fas fa-check-circle"></i>
        <span><?php echo htmlspecialchars($message); ?></span>
    </div>
    <?php endif; ?>

    <div class="settings-grid">
        <!-- MAIN FORM -->
        <div class="form-card">
            <div class="form-header">
                <h2>
                    <i class="fas fa-cog"></i> 
                    Core System Parameters
                </h2>
            </div>
            <div class="form-body">
                <form method="POST">
                    <div class="setting-group">
                        <label><i class="fas fa-percent"></i> Deposit Percentage</label>
                        <div class="input-wrapper">
                            <input type="number" name="deposit_percent" value="<?php echo $depositPercent; ?>" min="0" max="100" step="1" required>
                            <span class="input-symbol">%</span>
                        </div>
                        <small>Buyer and seller each deposit this % of total transaction value. Held safely in escrow.</small>
                    </div>

                    <div class="setting-group">
                        <label><i class="fas fa-hand-holding-usd"></i> Commission Fee</label>
                        <div class="input-wrapper">
                            <input type="number" name="commission_percent" value="<?php echo $commissionPercent; ?>" min="0" max="100" step="1" required>
                            <span class="input-symbol">%</span>
                        </div>
                        <small>Platform revenue share deducted from the final payout to seller.</small>
                    </div>

                    <div class="setting-group">
                        <label><i class="fas fa-clock"></i> Escrow Hold Period</label>
                        <div class="input-wrapper">
                            <input type="number" name="escrow_days" value="<?php echo $escrowDays; ?>" min="1" max="90" step="1" required>
                            <span class="input-symbol">days</span>
                        </div>
                        <small>Duration funds remain locked after transaction completion, ensuring dispute resolution.</small>
                    </div>

                    <div class="setting-group">
                        <label><i class="fas fa-money-bill-wave"></i> Minimum Withdrawal</label>
                        <div class="input-wrapper">
                            <input type="number" name="min_withdrawal" value="<?php echo $minWithdrawal; ?>" min="1" step="1" required>
                            <span class="input-symbol">ETB</span>
                        </div>
                        <small>Lowest amount users can request to withdraw from their wallet.</small>
                    </div>

                    <div class="setting-group">
                        <label><i class="fas fa-chart-line"></i> Maximum Withdrawal</label>
                        <div class="input-wrapper">
                            <input type="number" name="max_withdrawal" value="<?php echo $maxWithdrawal; ?>" min="1" step="1" required>
                            <span class="input-symbol">ETB</span>
                        </div>
                        <small>Per-request withdrawal ceiling to manage risk and compliance.</small>
                    </div>

                    <button type="submit" name="save_settings" class="btn-modern">
                        <i class="fas fa-save"></i> Apply Changes
                    </button>
                </form>
            </div>
        </div>

        <!-- RIGHT PREVIEW CARD (dynamic preview) -->
        <div class="preview-card">
            <div class="preview-header">
                <h3>
                    <i class="fas fa-calculator"></i> 
                    Live Preview Simulation
                </h3>
            </div>
            <div class="preview-body">
                <div class="calc-row">
                    <span class="calc-label">📦 Item Price (sample)</span>
                    <span class="calc-value">1,000.00 ETB</span>
                </div>
                <div class="calc-row">
                    <span class="calc-label">🔒 Deposit (<?php echo $depositPercent; ?>% each)</span>
                    <span class="calc-value"><?php echo number_format(1000 * $depositPercent / 100, 2); ?> ETB <span style="color:#6c757d;">(Buyer + Seller)</span></span>
                </div>
                <div class="calc-row">
                    <span class="calc-label">🏛️ Platform Commission (<?php echo $commissionPercent; ?>%)</span>
                    <span class="calc-value"><?php echo number_format(1000 * $commissionPercent / 100, 2); ?> ETB</span>
                </div>
                <div class="separator"></div>
                <div class="calc-row">
                    <span class="calc-label">💳 Buyer pays upfront</span>
                    <span class="calc-value"><strong><?php echo number_format(1000 * ($depositPercent + $commissionPercent) / 100, 2); ?> ETB</strong></span>
                </div>
                <div class="calc-row">
                    <span class="calc-label">💰 Seller receives (net)</span>
                    <span class="calc-value calc-total"><?php echo number_format(1000 * (100 - $commissionPercent) / 100, 2); ?> ETB</span>
                </div>
                
                <div class="highlight-box">
                    <i class="fas fa-shield-alt"></i>
                    <p><strong>Escrow flow overview:</strong> Both deposits locked → successful trade confirmation → seller gets paid minus fee, deposits returned.</p>
                </div>
                
                <div style="margin-top: 1rem; font-size:0.7rem; background:#faf5ff; border-radius:1rem; padding:0.7rem;">
                    <p style="display:flex; gap:12px; flex-wrap:wrap; margin:0;">
                        <span><span class="badge-step">1</span> Buyer deposit + fee</span>
                        <span><span class="badge-step">2</span> Seller deposit</span>
                        <span><span class="badge-step">3</span> Milestone release</span>
                        <span><span class="badge-step">4</span> Completion</span>
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include 'layout.php';
?>

BRS/admin/tickets.php

<?php
// admin/tickets.php - Support Tickets

$page_title = 'Support Tickets';
ob_start();

require_once '../config/database.php';
require_once '../includes/functions.php';

$conn = getDbConnection();
$message = '';

// Handle ticket actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_ticket'])) {
        $ticketId = intval($_POST['ticket_id']);
        $status = $_POST['status'];
        $conn->query("UPDATE support_tickets SET status = '$status', updated_at = NOW() WHERE id = $ticketId");
        $message = "Ticket updated";
    }
    if (isset($_POST['add_reply'])) {
        $ticketId = intval($_POST['ticket_id']);
        $reply = $conn->real_escape_string($_POST['reply']);
        $adminId = $_SESSION['admin_id'] ?? 1;
        $conn->query("INSERT INTO ticket_replies (ticket_id, user_id, message, is_admin) VALUES ($ticketId, $adminId, '$reply', 1)");
        $conn->query("UPDATE support_tickets SET status = 'in_progress', updated_at = NOW() WHERE id = $ticketId");
        $message = "Reply added";
    }
    if (isset($_POST['resolve_ticket'])) {
        $ticketId = intval($_POST['ticket_id']);
        $conn->query("UPDATE support_tickets SET status = 'resolved', resolved_at = NOW() WHERE id = $ticketId");
        $message = "Ticket resolved";
    }
}

$status = $_GET['status'] ?? '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$where = $status ? "WHERE t.status = '$status'" : "";
$sql = "SELECT t.*, u.full_name, u.email 
        FROM support_tickets t
        JOIN users u ON t.user_id = u.id
        $where
        ORDER BY FIELD(t.status, 'open', 'in_progress', 'resolved', 'closed'), t.created_at DESC
        LIMIT $offset, $limit";
$tickets = $conn->query($sql);

$total = $conn->query("SELECT COUNT(*) as count FROM support_tickets t $where")->fetch_assoc()['count'];
$totalPages = ceil($total / $limit);

$viewTicket = null;
$replies = null;
if (isset($_GET['view'])) {
    $viewId = intval($_GET['view']);
    $viewTicket = $conn->query("SELECT t.*, u.full_name, u.email FROM support_tickets t JOIN users u ON t.user_id = u.id WHERE t.id = $viewId")->fetch_assoc();
    if ($viewTicket) {
        $replies = $conn->query("SELECT r.*, u.full_name, u.role FROM ticket_replies r JOIN users u ON r.user_id = u.id WHERE r.ticket_id = $viewId ORDER BY r.created_at ASC");
    }
}

$stats = [
    'open' => $conn->query("SELECT COUNT(*) as count FROM support_tickets WHERE status = 'open'")->fetch_assoc()['count'],
    'in_progress' => $conn->query("SELECT COUNT(*) as count FROM support_tickets WHERE status = 'in_progress'")->fetch_assoc()['count'],
    'resolved' => $conn->query("SELECT COUNT(*) as count FROM support_tickets WHERE status = 'resolved'")->fetch_assoc()['count'],
    'total' => $conn->query("SELECT COUNT(*) as count FROM support_tickets")->fetch_assoc()['count'],
];

$conn->close();
?>

<style>
    .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 24px; }
    .stat-card { background: white; border-radius: 20px; padding: 20px; text-align: center; }
    .stat-value { font-size: 32px; font-weight: 700; }
    .filters { display: flex; gap: 12px; margin-bottom: 20px; }
    .filter-select { padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 10px; }
    .ticket-view { background: #f8fafc; padding: 20px; border-radius: 16px; margin-bottom: 20px; }
    .reply-item { background: white; padding: 16px; border-radius: 12px; margin-bottom: 12px; border-left: 3px solid #667eea; }
    .reply-admin { border-left-color: #10b981; background: #f0fdf4; }
    .reply-form textarea { width: 100%; padding: 12px; border: 1px solid #e2e8f0; border-radius: 12px; margin-bottom: 12px; }
    .btn-reply { background: #667eea; color: white; padding: 10px 20px; border: none; border-radius: 40px; cursor: pointer; }
    .btn-resolve { background: #10b981; color: white; }
    .pagination { display: flex; justify-content: center; gap: 8px; margin-top: 20px; }
    .pagination a, .pagination span { padding: 8px 12px; background: white; border: 1px solid #e2e8f0; border-radius: 8px; text-decoration: none; color: #333; }
    .pagination .active { background: #667eea; color: white; }
</style>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-value"><?php echo $stats['open']; ?></div><div class="stat-label">Open</div></div>
    <div class="stat-card"><div class="stat-value"><?php echo $stats['in_progress']; ?></div><div class="stat-label">In Progress</div></div>
    <div class="stat-card"><div class="stat-value"><?php echo $stats['resolved']; ?></div><div class="stat-label">Resolved</div></div>
    <div class="stat-card"><div class="stat-value"><?php echo $stats['total']; ?></div><div class="stat-label">Total</div></div>
</div>

<div class="filters">
    <select class="filter-select" onchange="location.href='?status='+this.value">
        <option value="">All</option>
        <option value="open" <?php echo $status == 'open' ? 'selected' : ''; ?>>Open</option>
        <option value="in_progress" <?php echo $status == 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
        <option value="resolved" <?php echo $status == 'resolved' ? 'selected' : ''; ?>>Resolved</option>
        <option value="closed" <?php echo $status == 'closed' ? 'selected' : ''; ?>>Closed</option>
    </select>
</div>

<?php if ($viewTicket): ?>
    <div class="card">
        <div class="card-header">
            <h2>Ticket #<?php echo $viewTicket['ticket_number']; ?></h2>
            <a href="tickets.php" class="btn-sm btn-primary">← Back</a>
        </div>
        <div class="ticket-view">
            <p><strong>From:</strong> <?php echo htmlspecialchars($viewTicket['full_name']); ?> (<?php echo htmlspecialchars($viewTicket['email']); ?>)</p>
            <p><strong>Subject:</strong> <?php echo htmlspecialchars($viewTicket['subject']); ?></p>
            <p><strong>Priority:</strong> <span class="badge badge-warning"><?php echo ucfirst($viewTicket['priority']); ?></span></p>
            <p><strong>Status:</strong> <span class="badge badge-info"><?php echo ucfirst($viewTicket['status']); ?></span></p>
            <p><strong>Created:</strong> <?php echo date('F d, Y H:i', strtotime($viewTicket['created_at'])); ?></p>
            <div style="margin-top: 16px; padding: 16px; background: white; border-radius: 12px;">
                <strong>Message:</strong>
                <p style="margin-top: 8px;"><?php echo nl2br(htmlspecialchars($viewTicket['message'])); ?></p>
            </div>
        </div>
        
        <h3>Conversation</h3>
        <?php if ($replies && $replies->num_rows > 0): ?>
            <?php while($reply = $replies->fetch_assoc()): ?>
            <div class="reply-item <?php echo $reply['is_admin'] ? 'reply-admin' : ''; ?>">
                <p><strong><?php echo $reply['is_admin'] ? '👨‍💼 Admin' : htmlspecialchars($reply['full_name']); ?></strong> <small><?php echo date('M d, H:i', strtotime($reply['created_at'])); ?></small></p>
                <p style="margin-top: 8px;"><?php echo nl2br(htmlspecialchars($reply['message'])); ?></p>
            </div>
            <?php endwhile; ?>
        <?php else: ?>
            <p>No replies yet.</p>
        <?php endif; ?>
        
        <div class="reply-form" style="margin-top: 20px;">
            <h3>Add Reply</h3>
            <form method="POST">
                <input type="hidden" name="ticket_id" value="<?php echo $viewTicket['id']; ?>">
                <textarea name="reply" rows="4" placeholder="Type your reply..." required></textarea>
                <button type="submit" name="add_reply" class="btn-reply">Send Reply</button>
                <?php if ($viewTicket['status'] != 'resolved' && $viewTicket['status'] != 'closed'): ?>
                <button type="submit" name="resolve_ticket" class="btn-reply btn-resolve" style="margin-left: 10px;">Mark Resolved</button>
                <?php endif; ?>
            </form>
        </div>
        
        <div style="margin-top: 20px;">
            <form method="POST">
                <input type="hidden" name="ticket_id" value="<?php echo $viewTicket['id']; ?>">
                <label>Update Status:</label>
                <select name="status" class="filter-select" style="margin-left: 10px;">
                    <option value="open" <?php echo $viewTicket['status'] == 'open' ? 'selected' : ''; ?>>Open</option>
                    <option value="in_progress" <?php echo $viewTicket['status'] == 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                    <option value="resolved" <?php echo $viewTicket['status'] == 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                    <option value="closed" <?php echo $viewTicket['status'] == 'closed' ? 'selected' : ''; ?>>Closed</option>
                </select>
                <button type="submit" name="update_ticket" class="btn-sm btn-primary">Update</button>
            </form>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <div class="card-header"><h2><i class="fas fa-ticket-alt"></i> Support Tickets</h2></div>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>Ticket #</th><th>User</th><th>Subject</th><th>Priority</th><th>Status</th><th>Created</th><th>Action</th></tr></thead>
                <tbody>
                    <?php while($row = $tickets->fetch_assoc()): ?>
                    <tr>
                        <td><strong><?php echo $row['ticket_number']; ?></strong></td>
                        <td><?php echo htmlspecialchars($row['full_name']); ?> <br><small><?php echo htmlspecialchars($row['email']); ?></small></td>
                        <td><?php echo htmlspecialchars(substr($row['subject'], 0, 40)); ?>...</td>
                        <td><span class="badge badge-warning"><?php echo ucfirst($row['priority']); ?></span></td>
                        <td><span class="badge badge-info"><?php echo ucfirst($row['status']); ?></span></td>
                        <td><?php echo timeAgo($row['created_at']); ?></td>
                        <td><a href="?view=<?php echo $row['id']; ?>" class="btn-sm btn-primary">View</a></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <a href="?page=<?php echo $i; ?>&status=<?php echo urlencode($status); ?>" class="<?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php
$content = ob_get_clean();
include 'layout.php';
?>

BRS/admin/transactions.php

<?php
// admin/transactions.php - Transactions Management with proper styling

$page_title = 'Transactions Management';
ob_start();

require_once '../config/database.php';
require_once '../includes/functions.php';

$conn = getDbConnection();

$status = $_GET['status'] ?? '';
$search = $_GET['search'] ?? '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$where = [];
if ($status) $where[] = "t.status = '$status'";
if ($search) $where[] = "(u1.full_name LIKE '%$search%' OR u2.full_name LIKE '%$search%' OR t.payment_code_5digit LIKE '%$search%')";
$whereClause = $where ? "WHERE " . implode(" AND ", $where) : "";

$transactions = $conn->query("
    SELECT t.*, u1.full_name as buyer_name, u2.full_name as seller_name 
    FROM transactions t
    LEFT JOIN users u1 ON t.buyer_id = u1.id
    LEFT JOIN users u2 ON t.seller_id = u2.id
    $whereClause
    ORDER BY t.created_at DESC
    LIMIT $limit OFFSET $offset
");

$total = $conn->query("SELECT COUNT(*) as total FROM transactions t $whereClause")->fetch_assoc()['total'];
$totalPages = ceil($total / $limit);

$stats = [
    'total' => $conn->query("SELECT COUNT(*) as count FROM transactions")->fetch_assoc()['count'],
    'completed' => $conn->query("SELECT COUNT(*) as count FROM transactions WHERE status = 'completed'")->fetch_assoc()['count'],
    'pending' => $conn->query("SELECT COUNT(*) as count FROM transactions WHERE status NOT IN ('completed', 'cancelled')")->fetch_assoc()['count'],
    'total_volume' => $conn->query("SELECT SUM(total_amount) as total FROM transactions WHERE status = 'completed'")->fetch_assoc()['total'] ?? 0,
];

$conn->close();
?>

<style>
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
        padding: 24px;
        text-align: center;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        transition: all 0.3s;
    }
    
    .stat-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 24px -8px rgba(0,0,0,0.15);
    }
    
    .stat-value {
        font-size: 32px;
        font-weight: 700;
        color: #0f172a;
    }
    
    .stat-label {
        font-size: 13px;
        color: #64748b;
        margin-top: 6px;
    }
    
    /* Filters */
    .filters {
        background: white;
        border-radius: 16px;
        padding: 20px;
        margin-bottom: 24px;
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
        align-items: flex-end;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    
    .filter-group {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }
    
    .filter-group label {
        font-size: 12px;
        color: #64748b;
        font-weight: 500;
    }
    
    .filter-group input, .filter-group select {
        padding: 10px 14px;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        font-size: 14px;
        min-width: 160px;
        background: #f8fafc;
        transition: all 0.3s;
    }
    
    .filter-group input:focus, .filter-group select:focus {
        outline: none;
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
    }
    
    .btn-filter {
        padding: 10px 24px;
        border-radius: 12px;
        border: none;
        background: #667eea;
        color: white;
        cursor: pointer;
        font-weight: 500;
        transition: all 0.3s;
    }
    
    .btn-filter:hover {
        background: #5a67d8;
        transform: translateY(-2px);
    }
    
    /* Card */
    .card {
        background: white;
        border-radius: 20px;
        padding: 24px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    
    .card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        padding-bottom: 16px;
        border-bottom: 2px solid #f1f5f9;
    }
    
    .card-header h2 {
        font-size: 18px;
        font-weight: 600;
        color: #0f172a;
    }
    
    .card-header span {
        font-size: 13px;
        color: #64748b;
        background: #f1f5f9;
        padding: 4px 12px;
        border-radius: 20px;
    }
    
    /* Table Styles */
    .table-wrapper {
        overflow-x: auto;
        border-radius: 12px;
    }
    
    table {
        width: 100%;
        border-collapse: collapse;
    }
    
    th, td {
        padding: 14px 12px;
        text-align: left;
        border-bottom: 1px solid #f1f5f9;
        font-size: 13px;
    }
    
    th {
        font-weight: 600;
        color: #64748b;
        background: #fafbfc;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    tr:hover {
        background: #f8fafc;
        cursor: pointer;
    }
    
    /* Badges */
    .badge {
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 500;
        display: inline-block;
    }
    
    .badge-success { background: #d1fae5; color: #059669; }
    .badge-warning { background: #fed7aa; color: #ea580c; }
    .badge-danger { background: #fee2e2; color: #dc2626; }
    .badge-info { background: #dbeafe; color: #2563eb; }
    .badge-secondary { background: #f1f5f9; color: #64748b; }
    
    /* Buttons */
    .btn-sm {
        padding: 6px 12px;
        font-size: 12px;
        border-radius: 8px;
        border: none;
        cursor: pointer;
        text-decoration: none;
        display: inline-block;
        transition: all 0.3s;
    }
    
    .btn-sm:hover {
        transform: translateY(-1px);
    }
    
    .btn-primary { background: #667eea; color: white; }
    
    /* Pagination */
    .pagination {
        display: flex;
        justify-content: center;
        gap: 8px;
        margin-top: 24px;
        padding-top: 16px;
        border-top: 1px solid #f1f5f9;
    }
    
    .pagination a, .pagination span {
        padding: 8px 14px;
        background: white;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        text-decoration: none;
        color: #475569;
        font-size: 13px;
        transition: all 0.3s;
    }
    
    .pagination a:hover {
        background: #667eea;
        color: white;
        border-color: #667eea;
    }
    
    .pagination .active {
        background: #667eea;
        color: white;
        border-color: #667eea;
    }
    
    code {
        background: #f1f5f9;
        padding: 2px 6px;
        border-radius: 6px;
        font-size: 11px;
        font-family: monospace;
    }
    
    @media (max-width: 1024px) {
        .stats-grid { grid-template-columns: repeat(2, 1fr); }
        .filters { flex-direction: column; align-items: stretch; }
        .filter-group input, .filter-group select { min-width: auto; }
    }
    
    @media (max-width: 768px) {
        .stats-grid { grid-template-columns: 1fr; }
        .card { padding: 16px; }
        th, td { padding: 10px 8px; font-size: 12px; }
    }
</style>

<!-- Statistics Cards -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($stats['total']); ?></div>
        <div class="stat-label">Total Transactions</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($stats['completed']); ?></div>
        <div class="stat-label">Completed</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($stats['pending']); ?></div>
        <div class="stat-label">Pending</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo formatMoney($stats['total_volume']); ?></div>
        <div class="stat-label">Total Volume</div>
    </div>
</div>

<!-- Filters -->
<form method="GET" class="filters">
    <div class="filter-group">
        <label>Status</label>
        <select name="status">
            <option value="">All Status</option>
            <option value="pending_deposit" <?php echo $status == 'pending_deposit' ? 'selected' : ''; ?>>Pending Deposit</option>
            <option value="awaiting_buyer_deposit" <?php echo $status == 'awaiting_buyer_deposit' ? 'selected' : ''; ?>>Awaiting Buyer Deposit</option>
            <option value="awaiting_seller_deposit" <?php echo $status == 'awaiting_seller_deposit' ? 'selected' : ''; ?>>Awaiting Seller Deposit</option>
            <option value="deposits_complete" <?php echo $status == 'deposits_complete' ? 'selected' : ''; ?>>Deposits Complete</option>
            <option value="in_progress" <?php echo $status == 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
            <option value="completed" <?php echo $status == 'completed' ? 'selected' : ''; ?>>Completed</option>
            <option value="disputed" <?php echo $status == 'disputed' ? 'selected' : ''; ?>>Disputed</option>
            <option value="cancelled" <?php echo $status == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
        </select>
    </div>
    <div class="filter-group">
        <label>Search</label>
        <input type="text" name="search" placeholder="Buyer, Seller, or Code" value="<?php echo htmlspecialchars($search); ?>">
    </div>
    <div class="filter-group">
        <button type="submit" class="btn-filter">Apply Filter</button>
    </div>
</form>

<!-- Transactions Table -->
<div class="card">
    <div class="card-header">
        <h2><i class="fas fa-exchange-alt"></i> All Transactions</h2>
        <span><?php echo number_format($total); ?> transactions</span>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Buyer</th>
                    <th>Seller</th>
                    <th>Amount</th>
                    <th>Commission</th>
                    <th>Status</th>
                    <th>Code</th>
                    <th>Date</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($transactions && $transactions->num_rows > 0): ?>
                    <?php while($row = $transactions->fetch_assoc()): ?>
                        <tr onclick="location.href='transactions.php?view=<?php echo $row['id']; ?>'">
                            <td>#<?php echo $row['id']; ?></td>
                            <td><?php echo htmlspecialchars($row['buyer_name'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($row['seller_name'] ?? 'N/A'); ?></td>
                            <td><strong><?php echo formatMoney($row['total_amount']); ?></strong></td>
                            <td><?php echo formatMoney($row['commission_amount']); ?></td>
                            <td><?php echo getStatusBadge($row['status']); ?></td>
                            <td><code><?php echo $row['payment_code_5digit'] ?? '-'; ?></code></td>
                            <td><?php echo date('M d, Y', strtotime($row['created_at'])); ?></td>
                            <td>
                                <a href="transactions.php?view=<?php echo $row['id']; ?>" class="btn-sm btn-primary" onclick="event.stopPropagation()">View</a>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="9" style="text-align: center; padding: 40px; color: #64748b;">
                            <i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 16px; display: block;"></i>
                            No transactions found
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <?php if ($totalPages > 1): ?>
    <div class="pagination">
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <a href="?page=<?php echo $i; ?>&status=<?php echo urlencode($status); ?>&search=<?php echo urlencode($search); ?>" class="<?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
include 'layout.php';
?>

BRS/admin/users.php

<?php
// admin/users.php - Premium Responsive Users Management

$page_title = 'User Management';
ob_start();

require_once '../config/database.php';
require_once '../includes/functions.php';

$conn = getDbConnection();
$message = '';
$error = '';

// Handle user actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = intval($_POST['user_id'] ?? 0);
    
    if (isset($_POST['ban_user'])) {
        $reason = $conn->real_escape_string($_POST['ban_reason']);
        $conn->query("UPDATE users SET is_suspended = 1, ban_reason = '$reason', banned_at = NOW() WHERE id = $userId");
        $message = "User banned successfully";
    }
    
    if (isset($_POST['unban_user'])) {
        $conn->query("UPDATE users SET is_suspended = 0, ban_reason = NULL, banned_at = NULL WHERE id = $userId");
        $message = "User unbanned successfully";
    }
    
    if (isset($_POST['delete_user'])) {
        $conn->query("DELETE FROM users WHERE id = $userId AND role != 'admin'");
        $message = "User deleted successfully";
    }
}

$search = $_GET['search'] ?? '';
$role = $_GET['role'] ?? '';
$status = $_GET['status'] ?? '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 15;
$offset = ($page - 1) * $limit;

$where = [];
if ($search) $where[] = "(full_name LIKE '%$search%' OR email LIKE '%$search%' OR phone LIKE '%$search%')";
if ($role) $where[] = "role = '$role'";
if ($status === 'active') $where[] = "is_suspended = 0";
if ($status === 'banned') $where[] = "is_suspended = 1";

$whereClause = $where ? "WHERE " . implode(" AND ", $where) : "";

$users = $conn->query("SELECT * FROM users $whereClause ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
$total = $conn->query("SELECT COUNT(*) as count FROM users $whereClause")->fetch_assoc()['count'];
$totalPages = ceil($total / $limit);

$stats = [
    'total' => $conn->query("SELECT COUNT(*) as count FROM users")->fetch_assoc()['count'],
    'active' => $conn->query("SELECT COUNT(*) as count FROM users WHERE is_suspended = 0")->fetch_assoc()['count'],
    'banned' => $conn->query("SELECT COUNT(*) as count FROM users WHERE is_suspended = 1")->fetch_assoc()['count'],
    'companies' => $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'company'")->fetch_assoc()['count'],
];

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <style>
        /* ============================================
           CSS VARIABLES & RESET
        ============================================ */
        :root {
            --primary: #4f46e5;
            --primary-dark: #4338ca;
            --primary-light: #eef2ff;
            --success: #10b981;
            --success-light: #d1fae5;
            --warning: #f59e0b;
            --warning-light: #fef3c7;
            --danger: #ef4444;
            --danger-light: #fee2e2;
            --info: #3b82f6;
            --info-light: #dbeafe;
            --dark: #1e293b;
            --gray: #64748b;
            --gray-light: #f8fafc;
            --border: #e2e8f0;
            --card-shadow: 0 1px 3px rgba(0,0,0,0.05), 0 1px 2px rgba(0,0,0,0.03);
            --card-shadow-hover: 0 10px 25px -5px rgba(0,0,0,0.08), 0 8px 10px -6px rgba(0,0,0,0.02);
            --transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: #f5f7fb;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
        }
        
        /* ============================================
           STATS CARDS - FULLY RESPONSIVE
        ============================================ */
        .stats-container {
            display: grid;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        /* Desktop: 4 columns */
        @media (min-width: 1200px) {
            .stats-container {
                grid-template-columns: repeat(4, 1fr);
                gap: 1.25rem;
            }
        }
        
        /* Laptop: 4 columns */
        @media (min-width: 992px) and (max-width: 1199px) {
            .stats-container {
                grid-template-columns: repeat(4, 1fr);
                gap: 1rem;
            }
        }
        
        /* Tablet: 2 columns */
        @media (min-width: 576px) and (max-width: 991px) {
            .stats-container {
                grid-template-columns: repeat(2, 1fr);
                gap: 1rem;
            }
        }
        
        /* Mobile: 1 column */
        @media (max-width: 575px) {
            .stats-container {
                grid-template-columns: 1fr;
                gap: 0.75rem;
            }
        }
        
        .stat-card {
            background: white;
            border-radius: 1rem;
            padding: 1.25rem;
            border: 1px solid var(--border);
            transition: var(--transition);
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
            background: linear-gradient(135deg, var(--primary), #7c3aed);
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--card-shadow-hover);
        }
        
        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.75rem;
        }
        
        .stat-icon {
            width: 42px;
            height: 42px;
            background: var(--primary-light);
            border-radius: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            color: var(--primary);
        }
        
        .stat-number {
            font-size: 1.75rem;
            font-weight: 800;
            color: var(--dark);
            letter-spacing: -0.02em;
        }
        
        .stat-label {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        /* ============================================
           FILTERS SECTION - FULLY RESPONSIVE
        ============================================ */
        .filters-card {
            background: white;
            border-radius: 1rem;
            padding: 1.25rem;
            margin-bottom: 1.5rem;
            border: 1px solid var(--border);
        }
        
        .filters-form {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            align-items: flex-end;
        }
        
        .filter-group {
            flex: 1;
            min-width: 140px;
        }
        
        .filter-group label {
            display: block;
            font-size: 0.7rem;
            font-weight: 600;
            color: var(--gray);
            margin-bottom: 0.375rem;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        
        .filter-input, .filter-select {
            width: 100%;
            padding: 0.625rem 0.875rem;
            border: 1px solid var(--border);
            border-radius: 0.5rem;
            font-size: 0.8rem;
            background: white;
            transition: var(--transition);
        }
        
        .filter-input:focus, .filter-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79,70,229,0.1);
        }
        
        .filter-actions {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }
        
        .btn-primary, .btn-secondary {
            padding: 0.625rem 1.25rem;
            border-radius: 0.5rem;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            white-space: nowrap;
        }
        
        .btn-primary {
            background: var(--primary);
            color: white;
        }
        
        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
        }
        
        .btn-secondary {
            background: var(--gray);
            color: white;
        }
        
        .btn-secondary:hover {
            background: #475569;
            transform: translateY(-1px);
        }
        
        /* Responsive Filters */
        @media (max-width: 992px) {
            .filters-form {
                flex-direction: column;
                align-items: stretch;
            }
            
            .filter-group {
                min-width: 100%;
            }
            
            .filter-actions {
                flex-direction: row;
                margin-top: 0.5rem;
            }
            
            .btn-primary, .btn-secondary {
                flex: 1;
                justify-content: center;
            }
        }
        
        /* ============================================
           USERS TABLE - RESPONSIVE
        ============================================ */
        .table-card {
            background: white;
            border-radius: 1rem;
            border: 1px solid var(--border);
            overflow: hidden;
        }
        
        .table-header {
            padding: 1rem 1.25rem;
            background: var(--gray-light);
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.75rem;
        }
        
        .table-header h3 {
            font-size: 1rem;
            font-weight: 700;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .table-header span {
            font-size: 0.75rem;
            color: var(--gray);
            background: white;
            padding: 0.25rem 0.75rem;
            border-radius: 2rem;
            border: 1px solid var(--border);
        }
        
        /* Desktop Table */
        .table-wrapper {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        
        .users-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8rem;
            min-width: 900px;
        }
        
        .users-table th {
            text-align: left;
            padding: 1rem 1rem;
            background: #fafcff;
            font-weight: 600;
            color: var(--gray);
            border-bottom: 1px solid var(--border);
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .users-table td {
            padding: 1rem 1rem;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
        }
        
        .users-table tr {
            transition: var(--transition);
        }
        
        .users-table tr:hover {
            background: var(--gray-light);
        }
        
        /* User Info Cell */
        .user-info-cell {
            display: flex;
            flex-direction: column;
        }
        
        .user-name {
            font-weight: 700;
            color: var(--dark);
            font-size: 0.85rem;
        }
        
        .user-id {
            font-size: 0.65rem;
            color: #94a3b8;
            margin-top: 0.125rem;
        }
        
        /* Badges */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.625rem;
            border-radius: 2rem;
            font-size: 0.7rem;
            font-weight: 600;
            white-space: nowrap;
        }
        
        .badge-active { background: var(--success-light); color: #065f46; }
        .badge-banned { background: var(--danger-light); color: #991b1b; }
        .badge-admin { background: linear-gradient(135deg, var(--primary), #7c3aed); color: white; }
        .badge-company { background: var(--info-light); color: #1e40af; }
        .badge-user { background: #f1f5f9; color: #475569; }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        
        .action-btn {
            padding: 0.375rem 0.875rem;
            border-radius: 0.5rem;
            font-size: 0.7rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            white-space: nowrap;
        }
        
        .action-view { background: var(--info); color: white; }
        .action-view:hover { background: #2563eb; transform: translateY(-1px); }
        .action-ban { background: var(--danger); color: white; }
        .action-ban:hover { background: #dc2626; transform: translateY(-1px); }
        .action-unban { background: var(--success); color: white; }
        .action-unban:hover { background: #059669; transform: translateY(-1px); }
        .action-delete { background: #dc2626; color: white; }
        .action-delete:hover { background: #b91c1c; transform: translateY(-1px); }
        
        /* Mobile Actions - Compact */
        @media (max-width: 576px) {
            .action-btn span {
                display: none;
            }
            
            .action-btn {
                padding: 0.375rem 0.625rem;
            }
            
            .action-btn i {
                margin: 0;
                font-size: 0.8rem;
            }
        }
        
        /* ============================================
           PAGINATION - RESPONSIVE
        ============================================ */
        .pagination {
            padding: 1rem 1.25rem;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        
        .page-link {
            padding: 0.375rem 0.875rem;
            border-radius: 0.5rem;
            text-decoration: none;
            color: var(--gray);
            font-size: 0.8rem;
            border: 1px solid var(--border);
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
        }
        
        .page-link:hover, .page-link.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        
        @media (max-width: 576px) {
            .page-link span {
                display: none;
            }
            
            .page-link i {
                margin: 0;
            }
        }
        
        /* ============================================
           MODALS - FULLY RESPONSIVE
        ============================================ */
        .modal, .view-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.6);
            backdrop-filter: blur(4px);
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }
        
        .modal-content, .view-modal-content {
            background: white;
            border-radius: 1.25rem;
            width: 90%;
            max-width: 500px;
            max-height: 85vh;
            overflow-y: auto;
            animation: modalIn 0.2s ease;
        }
        
        .view-modal-content {
            max-width: 550px;
        }
        
        @keyframes modalIn {
            from { opacity: 0; transform: scale(0.95); }
            to { opacity: 1; transform: scale(1); }
        }
        
        .modal-header, .view-modal-header {
            padding: 1.25rem 1.5rem;
            background: linear-gradient(135deg, var(--primary), #7c3aed);
            color: white;
            border-radius: 1.25rem 1.25rem 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .modal-header h3, .view-modal-header h3 {
            font-size: 1.1rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .close-modal {
            cursor: pointer;
            font-size: 1.25rem;
            transition: opacity 0.2s;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: rgba(255,255,255,0.1);
        }
        
        .close-modal:hover {
            background: rgba(255,255,255,0.2);
        }
        
        .view-modal-body {
            padding: 1.5rem;
        }
        
        /* Profile Section in Modal */
        .profile-section {
            display: flex;
            align-items: center;
            gap: 1.25rem;
            margin-bottom: 1.5rem;
            padding-bottom: 1.25rem;
            border-bottom: 1px solid var(--border);
            flex-wrap: wrap;
        }
        
        .profile-avatar {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, var(--primary), #7c3aed);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.75rem;
            font-weight: 700;
            color: white;
        }
        
        .profile-details h3 {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }
        
        .profile-details p {
            font-size: 0.7rem;
            color: var(--gray);
        }
        
        /* Info Grid in Modal */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .info-item {
            background: var(--gray-light);
            border-radius: 0.75rem;
            padding: 0.875rem;
        }
        
        .info-label {
            font-size: 0.65rem;
            font-weight: 600;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-bottom: 0.375rem;
            display: flex;
            align-items: center;
            gap: 0.375rem;
        }
        
        .info-value {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--dark);
            word-break: break-word;
        }
        
        /* Stats Row in Modal */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.75rem;
            margin-bottom: 1.5rem;
        }
        
        .stat-item {
            background: var(--primary-light);
            border-radius: 0.75rem;
            padding: 0.875rem;
            text-align: center;
        }
        
        .stat-number {
            font-size: 1.25rem;
            font-weight: 800;
            color: var(--primary);
        }
        
        .stat-text {
            font-size: 0.65rem;
            color: var(--gray);
            margin-top: 0.25rem;
        }
        
        /* Modal Action Buttons */
        .modal-actions {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border);
        }
        
        .form-group {
            margin-bottom: 1.25rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            font-size: 0.8rem;
            color: var(--dark);
        }
        
        .form-group textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--border);
            border-radius: 0.75rem;
            resize: vertical;
            font-family: inherit;
            font-size: 0.85rem;
        }
        
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary);
        }
        
        /* Responsive Modal */
        @media (max-width: 576px) {
            .modal-content, .view-modal-content {
                width: 95%;
                margin: 1rem;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
                gap: 0.75rem;
            }
            
            .stats-row {
                grid-template-columns: 1fr;
                gap: 0.75rem;
            }
            
            .profile-section {
                flex-direction: column;
                text-align: center;
            }
            
            .modal-actions {
                flex-direction: column;
            }
            
            .modal-actions .action-btn {
                width: 100%;
                justify-content: center;
            }
        }
        
        /* Alert */
        .alert {
            padding: 0.875rem 1rem;
            border-radius: 0.75rem;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.625rem;
            font-size: 0.8rem;
            animation: slideIn 0.3s ease;
        }
        
        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .alert-success {
            background: var(--success-light);
            color: #065f46;
            border-left: 3px solid var(--success);
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--gray);
        }
        
        .empty-state i {
            font-size: 3rem;
            color: #cbd5e1;
            margin-bottom: 1rem;
            display: block;
        }
    </style>
</head>

<div>
    <!-- Alert Message -->
    <?php if ($message): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> <?php echo $message; ?>
        </div>
    <?php endif; ?>
    
    <!-- Statistics Cards - Fully Responsive Grid -->
    <div class="stats-container">
        <div class="stat-card">
            <div class="stat-header">
                <div class="stat-icon"><i class="fas fa-users"></i></div>
            </div>
            <div class="stat-number"><?php echo number_format($stats['total']); ?></div>
            <div class="stat-label">Total Users</div>
        </div>
        <div class="stat-card">
            <div class="stat-header">
                <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
            </div>
            <div class="stat-number"><?php echo number_format($stats['active']); ?></div>
            <div class="stat-label">Active</div>
        </div>
        <div class="stat-card">
            <div class="stat-header">
                <div class="stat-icon"><i class="fas fa-ban"></i></div>
            </div>
            <div class="stat-number"><?php echo number_format($stats['banned']); ?></div>
            <div class="stat-label">Banned</div>
        </div>
        <div class="stat-card">
            <div class="stat-header">
                <div class="stat-icon"><i class="fas fa-building"></i></div>
            </div>
            <div class="stat-number"><?php echo number_format($stats['companies']); ?></div>
            <div class="stat-label">Companies</div>
        </div>
    </div>
    
    <!-- Filters Section - Fully Responsive -->
    <div class="filters-card">
        <form method="GET" class="filters-form">
            <div class="filter-group">
                <label><i class="fas fa-search"></i> Search</label>
                <input type="text" name="search" class="filter-input" placeholder="Name, email or phone..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="filter-group">
                <label><i class="fas fa-tag"></i> Role</label>
                <select name="role" class="filter-select">
                    <option value="">All Roles</option>
                    <option value="user" <?php echo $role == 'user' ? 'selected' : ''; ?>>User</option>
                    <option value="company" <?php echo $role == 'company' ? 'selected' : ''; ?>>Company</option>
                    <option value="admin" <?php echo $role == 'admin' ? 'selected' : ''; ?>>Admin</option>
                </select>
            </div>
            <div class="filter-group">
                <label><i class="fas fa-flag"></i> Status</label>
                <select name="status" class="filter-select">
                    <option value="">All Status</option>
                    <option value="active" <?php echo $status == 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="banned" <?php echo $status == 'banned' ? 'selected' : ''; ?>>Banned</option>
                </select>
            </div>
            <div class="filter-actions">
                <button type="submit" class="btn-primary"><i class="fas fa-filter"></i> Apply</button>
                <a href="users.php" class="btn-secondary"><i class="fas fa-undo"></i> Reset</a>
            </div>
        </form>
    </div>
    
    <!-- Users Table -->
    <div class="table-card">
        <div class="table-header">
            <h3><i class="fas fa-users"></i> All Users</h3>
            <span><i class="fas fa-database"></i> <?php echo number_format($total); ?> users</span>
        </div>
        <div class="table-wrapper">
            <?php if ($users && $users->num_rows > 0): ?>
                <table class="users-table">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Role</th>
                            <th>Balance</th>
                            <th>Status</th>
                            <th>Joined</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($row = $users->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <div class="user-info-cell">
                                        <span class="user-name"><?php echo htmlspecialchars($row['full_name']); ?></span>
                                        <span class="user-id"><i class="fas fa-hashtag"></i> ID: <?php echo $row['id']; ?></span>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars(substr($row['email'], 0, 25)); ?><?php echo strlen($row['email']) > 25 ? '...' : ''; ?></td>
                                <td><?php echo htmlspecialchars($row['phone'] ?? '-'); ?></td>
                                <td>
                                    <?php if($row['role'] == 'admin'): ?>
                                        <span class="badge badge-admin"><i class="fas fa-shield-alt"></i> Admin</span>
                                    <?php elseif($row['role'] == 'company'): ?>
                                        <span class="badge badge-company"><i class="fas fa-building"></i> Company</span>
                                    <?php else: ?>
                                        <span class="badge badge-user"><i class="fas fa-user"></i> User</span>
                                    <?php endif; ?>
                                </td>
                                <td><strong><?php echo formatMoney($row['balance']); ?></strong></td>
                                <td>
                                    <?php if($row['is_suspended']): ?>
                                        <span class="badge badge-banned"><i class="fas fa-ban"></i> Banned</span>
                                    <?php else: ?>
                                        <span class="badge badge-active"><i class="fas fa-check-circle"></i> Active</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('M d, Y', strtotime($row['created_at'])); ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <button onclick="viewUser(<?php echo $row['id']; ?>)" class="action-btn action-view" title="View Details">
                                            <i class="fas fa-eye"></i> <span>View</span>
                                        </button>
                                        
                                        <?php if ($row['role'] != 'admin'): ?>
                                            <?php if ($row['is_suspended']): ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="user_id" value="<?php echo $row['id']; ?>">
                                                    <button type="submit" name="unban_user" class="action-btn action-unban" onclick="return confirm('Unban this user?')" title="Unban">
                                                        <i class="fas fa-check-circle"></i> <span>Unban</span>
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <button onclick="banUser(<?php echo $row['id']; ?>)" class="action-btn action-ban" title="Ban">
                                                    <i class="fas fa-ban"></i> <span>Ban</span>
                                                </button>
                                            <?php endif; ?>
                                            
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this user? This action cannot be undone.')">
                                                <input type="hidden" name="user_id" value="<?php echo $row['id']; ?>">
                                                <button type="submit" name="delete_user" class="action-btn action-delete" title="Delete">
                                                    <i class="fas fa-trash"></i> <span>Delete</span>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-users"></i>
                    <p>No users found</p>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&role=<?php echo urlencode($role); ?>&status=<?php echo urlencode($status); ?>" class="page-link">
                        <i class="fas fa-chevron-left"></i> <span>Prev</span>
                    </a>
                <?php endif; ?>
                
                <?php
                $start = max(1, $page - 2);
                $end = min($totalPages, $page + 2);
                for ($i = $start; $i <= $end; $i++): ?>
                    <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&role=<?php echo urlencode($role); ?>&status=<?php echo urlencode($status); ?>" 
                       class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
                        <?php echo $i; ?>
                    </a>
                <?php endfor; ?>
                
                <?php if ($page < $totalPages): ?>
                    <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&role=<?php echo urlencode($role); ?>&status=<?php echo urlencode($status); ?>" class="page-link">
                        <span>Next</span> <i class="fas fa-chevron-right"></i>
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- View User Modal -->
<div id="viewUserModal" class="view-modal">
    <div class="view-modal-content">
        <div class="view-modal-header">
            <h3><i class="fas fa-user-circle"></i> User Profile</h3>
            <span class="close-modal" onclick="closeViewModal()">&times;</span>
        </div>
        <div class="view-modal-body" id="viewUserContent">
            <div style="text-align: center; padding: 2rem;">
                <i class="fas fa-spinner fa-spin"></i> Loading...
            </div>
        </div>
    </div>
</div>

<!-- Ban Modal -->
<div id="banModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-ban"></i> Ban User</h3>
            <span class="close-modal" onclick="closeBanModal()">&times;</span>
        </div>
        <div style="padding: 1.5rem;">
            <form method="POST">
                <input type="hidden" name="user_id" id="banUserId">
                <div class="form-group">
                    <label>Reason for Banning</label>
                    <textarea name="ban_reason" rows="3" required placeholder="Enter reason for banning this user..."></textarea>
                </div>
                <button type="submit" name="ban_user" class="action-btn action-ban" style="width: 100%; padding: 0.75rem; justify-content: center;">
                    <i class="fas fa-ban"></i> Ban User
                </button>
            </form>
        </div>
    </div>
</div>

<script>
function viewUser(userId) {
    const modal = document.getElementById('viewUserModal');
    const content = document.getElementById('viewUserContent');
    
    modal.style.display = 'flex';
    content.innerHTML = '<div style="text-align: center; padding: 2rem;"><i class="fas fa-spinner fa-spin"></i> Loading user details...</div>';
    
    fetch(`ajax/get_user_details.php?id=${userId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const user = data.user;
                const isBanned = user.is_suspended == 1;
                
                content.innerHTML = `
                    <div class="profile-section">
                        <div class="profile-avatar">${user.full_name.charAt(0).toUpperCase()}</div>
                        <div class="profile-details">
                            <h3>${escapeHtml(user.full_name)}</h3>
                            <p><i class="fas fa-calendar-alt"></i> Member since ${new Date(user.created_at).toLocaleDateString()}</p>
                            <span class="badge ${isBanned ? 'badge-banned' : 'badge-active'}" style="margin-top: 0.5rem;">
                                ${isBanned ? '<i class="fas fa-ban"></i> Banned' : '<i class="fas fa-check-circle"></i> Active'}
                            </span>
                        </div>
                    </div>
                    
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label"><i class="fas fa-envelope"></i> Email</div>
                            <div class="info-value">${escapeHtml(user.email)}</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label"><i class="fas fa-phone"></i> Phone</div>
                            <div class="info-value">${user.phone || '<span style="color: #94a3b8;">Not provided</span>'}</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label"><i class="fas fa-tag"></i> Role</div>
                            <div class="info-value">${user.role.charAt(0).toUpperCase() + user.role.slice(1)}</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label"><i class="fas fa-map-marker-alt"></i> Address</div>
                            <div class="info-value">${user.address || '<span style="color: #94a3b8;">Not provided</span>'}</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label"><i class="fas fa-city"></i> City</div>
                            <div class="info-value">${user.city || '<span style="color: #94a3b8;">Not provided</span>'}</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label"><i class="fas fa-wallet"></i> Balance</div>
                            <div class="info-value">${formatMoney(user.balance)}</div>
                        </div>
                        ${user.bio ? `
                        <div class="info-item">
                            <div class="info-label"><i class="fas fa-align-left"></i> Bio</div>
                            <div class="info-value">${escapeHtml(user.bio)}</div>
                        </div>
                        ` : ''}
                        ${user.ban_reason ? `
                        <div class="info-item">
                            <div class="info-label"><i class="fas fa-exclamation-triangle"></i> Ban Reason</div>
                            <div class="info-value" style="color: #dc2626;">${escapeHtml(user.ban_reason)}</div>
                        </div>
                        ` : ''}
                    </div>
                    
                    <div class="stats-row">
                        <div class="stat-item">
                            <div class="stat-number">${user.total_listings || 0}</div>
                            <div class="stat-text">Listings</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-number">${user.total_transactions || 0}</div>
                            <div class="stat-text">Transactions</div>
                        </div>
                        <div const="stat-item">
                            <div class="stat-number">${formatMoney(user.balance)}</div>
                            <div class="stat-text">Balance</div>
                        </div>
                    </div>
                    
                    <div class="modal-actions">
                        ${!isBanned && user.role != 'admin' ? `
                            <button onclick="banUser(${user.id})" class="action-btn action-ban" style="flex: 1; justify-content: center;">
                                <i class="fas fa-ban"></i> Ban User
                            </button>
                        ` : isBanned ? `
                            <form method="POST" style="flex: 1;">
                                <input type="hidden" name="user_id" value="${user.id}">
                                <button type="submit" name="unban_user" class="action-btn action-unban" style="width: 100%; justify-content: center;">
                                    <i class="fas fa-check-circle"></i> Unban User
                                </button>
                            </form>
                        ` : ''}
                        ${user.role != 'admin' ? `
                            <form method="POST" style="flex: 1;" onsubmit="return confirm('Delete this user?')">
                                <input type="hidden" name="user_id" value="${user.id}">
                                <button type="submit" name="delete_user" class="action-btn action-delete" style="width: 100%; justify-content: center;">
                                    <i class="fas fa-trash"></i> Delete User
                                </button>
                            </form>
                        ` : ''}
                        <a href="chat.php?user=${user.id}" class="action-btn action-view" style="flex: 1; justify-content: center; text-decoration: none;">
                            <i class="fas fa-comment"></i> Send Message
                        </a>
                        <button onclick="closeViewModal()" class="action-btn" style="flex: 1; justify-content: center; background: #64748b; color: white;">
                            <i class="fas fa-times"></i> Close
                        </button>
                    </div>
                `;
            } else {
                content.innerHTML = `<div style="text-align: center; padding: 2rem; color: #dc2626;">Failed to load user details</div>`;
            }
        })
        .catch(error => {
            content.innerHTML = `<div style="text-align: center; padding: 2rem; color: #dc2626;">Error loading user details</div>`;
        });
}

function closeViewModal() {
    document.getElementById('viewUserModal').style.display = 'none';
}

function banUser(id) { 
    document.getElementById('banUserId').value = id; 
    document.getElementById('banModal').style.display = 'flex'; 
    closeViewModal();
}

function closeBanModal() { 
    document.getElementById('banModal').style.display = 'none'; 
}

function formatMoney(amount) {
    return new Intl.NumberFormat('en-US', { minimumFractionDigits: 2 }).format(amount) + ' ETB';
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

window.onclick = function(event) { 
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none'; 
    }
    if (event.target.classList.contains('view-modal')) {
        event.target.style.display = 'none';
    }
}
</script>

<?php
$content = ob_get_clean();
include 'layout.php';
?>

BRS/admin/withdrawals.php

<?php
// admin/withdrawals.php - Admin Withdrawal Management

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/validation.php';
require_once '../includes/telebirr_simulation.php';

requireAdminLogin();

$page_title = 'Withdrawal Management';
ob_start();

$conn = getDbConnection();
$message = '';
$error = '';

// Handle withdrawal actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $withdrawal_id = sanitizeInt($_POST['withdrawal_id'] ?? 0);
    $action = sanitizeString($_POST['action'] ?? '');
    $admin_notes = sanitizeString($_POST['admin_notes'] ?? '');
    $admin_id = $_SESSION['user_id'];
    
    if ($action === 'approve') {
        $stmt = $conn->prepare("SELECT user_id, amount, telebirr_phone FROM withdrawal_requests WHERE id = ? AND status = 'pending'");
        $stmt->bind_param('i', $withdrawal_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $wd = $result ? $result->fetch_assoc() : null;

        if (!$wd) {
            $error = "Withdrawal request not found or already processed";
        } elseif (empty($wd['telebirr_phone'])) {
            $error = "Telebirr phone is missing for this withdrawal";
        } else {
            $transferError = null;
            $transfer = performTelebirrTransfer(getPlatformTelebirrPhone(), $wd['telebirr_phone'], $wd['amount'], 'Withdrawal payout', null, $transferError);

            if ($transfer === false) {
                $conn->begin_transaction();
                try {
                    $update = $conn->prepare("UPDATE withdrawal_requests SET status = 'failed', telebirr_transfer_reference = ?, telebirr_transfer_status = 'failed', telebirr_sender_phone = ?, telebirr_receiver_phone = ?, telebirr_transfer_amount = ?, telebirr_transfer_message = ?, admin_notes = ?, processed_by = ?, processed_at = NOW() WHERE id = ?");
                    $reference = generateTelebirrTransferReference();
                    $update->bind_param('sssdssii', $reference, getPlatformTelebirrPhone(), $wd['telebirr_phone'], $wd['amount'], $transferError, $admin_notes, $admin_id, $withdrawal_id);
                    $update->execute();

                    $refund = $conn->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
                    $refund->bind_param('di', $wd['amount'], $wd['user_id']);
                    $refund->execute();

                    $walletDesc = "Refund after failed Telebirr transfer for withdrawal request #" . $withdrawal_id;
                    $walletTx = $conn->prepare("INSERT INTO wallet_transactions (user_id, amount, type, description, created_at) VALUES (?, ?, 'withdrawal_refund', ?, NOW())");
                    $walletTx->bind_param('ids', $wd['user_id'], $wd['amount'], $walletDesc);
                    $walletTx->execute();

                    $conn->commit();
                    $error = "Telebirr transfer failed: " . $transferError . " — amount has been refunded.";
                } catch (Exception $e) {
                    $conn->rollback();
                    $error = "Failed to process withdrawal approval: " . $e->getMessage();
                }
            } else {
                $conn->begin_transaction();
                try {
                    $update = $conn->prepare("UPDATE withdrawal_requests SET status = 'approved', telebirr_transfer_reference = ?, telebirr_transfer_status = 'success', telebirr_sender_phone = ?, telebirr_receiver_phone = ?, telebirr_transfer_amount = ?, telebirr_transfer_message = ?, admin_notes = ?, processed_by = ?, processed_at = NOW() WHERE id = ?");
                    $update->bind_param('sssdssii', $transfer['reference'], getPlatformTelebirrPhone(), $wd['telebirr_phone'], $transfer['amount'], $transfer['description'], $admin_notes, $admin_id, $withdrawal_id);
                    $update->execute();

                    $walletQuery = $conn->prepare("SELECT id FROM wallet_transactions WHERE user_id = ? AND amount = ? AND type = 'withdrawal_pending' ORDER BY created_at DESC LIMIT 1");
                    $walletQuery->bind_param('id', $wd['user_id'], $wd['amount']);
                    $walletQuery->execute();
                    $walletRow = $walletQuery->get_result();

                    $walletDescription = "Withdrawal approved and sent to Telebirr " . $wd['telebirr_phone'];
                    if ($walletRow && $walletRow->num_rows > 0) {
                        $tx = $walletRow->fetch_assoc();
                        $walletUpdate = $conn->prepare("UPDATE wallet_transactions SET type = 'withdrawal_approved', description = ? WHERE id = ?");
                        $walletUpdate->bind_param('si', $walletDescription, $tx['id']);
                        $walletUpdate->execute();
                    } else {
                        $walletTx = $conn->prepare("INSERT INTO wallet_transactions (user_id, amount, type, description, created_at) VALUES (?, ?, 'withdrawal_approved', ?, NOW())");
                        $walletTx->bind_param('ids', $wd['user_id'], $wd['amount'], $walletDescription);
                        $walletTx->execute();
                    }

                    $conn->commit();
                    $message = "Withdrawal approved and Telebirr transfer completed successfully.";
                } catch (Exception $e) {
                    $conn->rollback();
                    $error = "Failed to approve withdrawal: " . $e->getMessage();
                }
            }
        }
    } elseif ($action === 'complete') {
        $stmt = $conn->prepare("
            UPDATE withdrawal_requests 
            SET status = 'completed', admin_notes = ?, processed_by = ?, processed_at = NOW() 
            WHERE id = ?
        ");
        $stmt->bind_param("sii", $admin_notes, $admin_id, $withdrawal_id);
        
        if ($stmt->execute()) {
            $message = "Withdrawal marked as completed";
        } else {
            $error = "Failed to complete withdrawal";
        }
    } elseif ($action === 'reject') {
        $stmt = $conn->prepare("
            UPDATE withdrawal_requests 
            SET status = 'rejected', admin_notes = ?, processed_by = ?, processed_at = NOW() 
            WHERE id = ?
        ");
        $stmt->bind_param("sii", $admin_notes, $admin_id, $withdrawal_id);
        
        if ($stmt->execute()) {
            // Refund the amount back to user
            $wd = $conn->query("SELECT user_id, amount FROM withdrawal_requests WHERE id = $withdrawal_id")->fetch_assoc();
            if ($wd) {
                $conn->query("UPDATE users SET balance = balance + {$wd['amount']} WHERE id = {$wd['user_id']}");
                $conn->query("INSERT INTO wallet_transactions (user_id, amount, type, description, created_at) 
                    VALUES ({$wd['user_id']}, {$wd['amount']}, 'deposit', 'Withdrawal rejection refund', NOW())");
            }
            $message = "Withdrawal rejected and amount refunded";
        } else {
            $error = "Failed to reject withdrawal";
        }
    }
}

// Get filter
$status_filter = sanitizeString($_GET['status'] ?? '');
$page = sanitizeInt($_GET['page'] ?? 1);
$limit = 20;
$offset = ($page - 1) * $limit;

$where = $status_filter ? "WHERE w.status = '$status_filter'" : "";
$sql = "SELECT w.*, u.full_name, u.email, u.phone, u.balance
        FROM withdrawal_requests w
        JOIN users u ON w.user_id = u.id
        $where
        ORDER BY FIELD(w.status, 'pending', 'approved', 'completed', 'rejected'), w.created_at DESC
        LIMIT $offset, $limit";
$withdrawals = $conn->query($sql);

$total = $conn->query("SELECT COUNT(*) as count FROM withdrawal_requests w $where")->fetch_assoc()['count'];
$totalPages = ceil($total / $limit);

// Statistics
$stats = [
    'pending' => $conn->query("SELECT SUM(amount) as total, COUNT(*) as count FROM withdrawal_requests WHERE status = 'pending'")->fetch_assoc(),
    'approved' => $conn->query("SELECT SUM(amount) as total, COUNT(*) as count FROM withdrawal_requests WHERE status = 'approved'")->fetch_assoc(),
    'completed' => $conn->query("SELECT SUM(amount) as total, COUNT(*) as count FROM withdrawal_requests WHERE status = 'completed'")->fetch_assoc(),
    'total_processed' => $conn->query("SELECT SUM(amount) as total FROM withdrawal_requests WHERE status IN ('approved', 'completed')")->fetch_assoc()['total'] ?? 0,
];

$conn->close();
?>

<style>
    .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 28px; }
    .stat-card { background: white; border-radius: 20px; padding: 24px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
    .stat-value { font-size: 28px; font-weight: 700; color: #0f172a; }
    .stat-label { font-size: 13px; color: #64748b; margin-top: 6px; }
    .stat-small { font-size: 11px; color: #94a3b8; margin-top: 4px; }
    
    .filters { margin-bottom: 24px; }
    .filter-select { padding: 10px 16px; border: 1px solid #e2e8f0; border-radius: 12px; font-size: 14px; background: white; min-width: 180px; }
    
    .card { background: white; border-radius: 20px; padding: 24px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
    .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 16px; border-bottom: 2px solid #f1f5f9; }
    
    .table-wrapper { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 14px 12px; text-align: left; border-bottom: 1px solid #f1f5f9; font-size: 13px; }
    th { font-weight: 600; color: #64748b; background: #fafbfc; }
    
    .badge { padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 500; display: inline-block; }
    .badge-pending { background: #fed7aa; color: #ea580c; }
    .badge-approved { background: #dbeafe; color: #2563eb; }
    .badge-completed { background: #d1fae5; color: #059669; }
    .badge-rejected { background: #fee2e2; color: #dc2626; }
    
    .btn-sm { padding: 6px 12px; font-size: 12px; border-radius: 8px; border: none; cursor: pointer; margin: 2px; }
    .btn-approve { background: #10b981; color: white; }
    .btn-reject { background: #ef4444; color: white; }
    .btn-complete { background: #667eea; color: white; }
    
    .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); align-items: center; justify-content: center; z-index: 1000; }
    .modal-content { background: white; border-radius: 20px; padding: 28px; width: 450px; max-width: 90%; }
    .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
    .close-modal { cursor: pointer; font-size: 28px; color: #94a3b8; }
    .form-group { margin-bottom: 20px; }
    .form-group textarea { width: 100%; padding: 12px; border: 1px solid #e2e8f0; border-radius: 12px; font-family: inherit; resize: vertical; }
    
    .pagination { display: flex; justify-content: center; gap: 8px; margin-top: 24px; }
    .pagination a, .pagination span { padding: 8px 14px; background: white; border: 1px solid #e2e8f0; border-radius: 10px; text-decoration: none; color: #475569; font-size: 13px; }
    .pagination a:hover, .pagination .active { background: #667eea; color: white; border-color: #667eea; }
    
    .empty-state { text-align: center; padding: 60px; color: #94a3b8; }
    .empty-state i { font-size: 48px; margin-bottom: 16px; display: block; }
    
    @media (max-width: 768px) { .stats-grid { grid-template-columns: repeat(2, 1fr); } }
</style>

<!-- Statistics -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-value"><?php echo formatMoney($stats['pending']['total'] ?? 0); ?></div>
        <div class="stat-label">Pending Amount</div>
        <div class="stat-small"><?php echo number_format($stats['pending']['count'] ?? 0); ?> requests</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo formatMoney($stats['approved']['total'] ?? 0); ?></div>
        <div class="stat-label">Approved Amount</div>
        <div class="stat-small"><?php echo number_format($stats['approved']['count'] ?? 0); ?> requests</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo formatMoney($stats['completed']['total'] ?? 0); ?></div>
        <div class="stat-label">Completed Amount</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo formatMoney($stats['total_processed']); ?></div>
        <div class="stat-label">Total Processed</div>
    </div>
</div>

<!-- Filters -->
<div class="filters">
    <select class="filter-select" onchange="location.href='?status='+this.value">
        <option value="">All Status</option>
        <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>Pending</option>
        <option value="approved" <?php echo $status_filter == 'approved' ? 'selected' : ''; ?>>Approved</option>
        <option value="completed" <?php echo $status_filter == 'completed' ? 'selected' : ''; ?>>Completed</option>
        <option value="rejected" <?php echo $status_filter == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
    </select>
</div>

<?php if ($message): ?>
    <div class="alert alert-success" style="background:#d1fae5; color:#059669; padding:12px; border-radius:12px; margin-bottom:20px;"><?php echo $message; ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-error" style="background:#fee2e2; color:#dc2626; padding:12px; border-radius:12px; margin-bottom:20px;"><?php echo $error; ?></div>
<?php endif; ?>

<!-- Withdrawals Table -->
<div class="card">
    <div class="card-header">
        <h2><i class="fas fa-money-bill-wave"></i> Withdrawal Requests</h2>
        <span><?php echo number_format($total); ?> requests</span>
    </div>
    <div class="table-wrapper">
        <?php if ($withdrawals && $withdrawals->num_rows > 0): ?>
            <table>
                <thead>
                    <tr><th>ID</th><th>User</th><th>Amount</th><th>Telebirr Phone</th><th>Status</th><th>Requested</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    <?php while($row = $withdrawals->fetch_assoc()): ?>
                    <tr>
                        <td>#<?php echo $row['id']; ?></td>
                        <td><strong><?php echo htmlspecialchars($row['full_name']); ?></strong><br><small><?php echo htmlspecialchars($row['email']); ?></small></td>
                        <td><strong><?php echo formatMoney($row['amount']); ?></strong></td>
                        <td><?php echo htmlspecialchars($row['telebirr_phone'] ?: $row['bank_name']); ?><br><small><?php echo htmlspecialchars($row['account_number']); ?></small></td>
                        <td>
                            <?php
                            $badgeClass = match($row['status']) {
                                'pending' => 'badge-pending',
                                'approved' => 'badge-approved',
                                'completed' => 'badge-completed',
                                'rejected' => 'badge-rejected',
                                default => ''
                            };
                            ?>
                            <span class="badge <?php echo $badgeClass; ?>"><?php echo ucfirst($row['status']); ?></span>
                        </td>
                        <td><?php echo date('M d, H:i', strtotime($row['created_at'])); ?></td>
                        <td>
                            <?php if ($row['status'] == 'pending'): ?>
                                <button onclick="openActionModal('approve', <?php echo $row['id']; ?>)" class="btn-sm btn-approve">Approve</button>
                                <button onclick="openActionModal('reject', <?php echo $row['id']; ?>)" class="btn-sm btn-reject">Reject</button>
                            <?php elseif ($row['status'] == 'approved'): ?>
                                <button onclick="openActionModal('complete', <?php echo $row['id']; ?>)" class="btn-sm btn-complete">Mark Complete</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="empty-state"><i class="fas fa-inbox"></i><p>No withdrawal requests found</p></div>
        <?php endif; ?>
    </div>
    
    <?php if ($totalPages > 1): ?>
    <div class="pagination">
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <a href="?page=<?php echo $i; ?>&status=<?php echo urlencode($status_filter); ?>" class="<?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Action Modal -->
<div id="actionModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="modalTitle">Process Withdrawal</h3>
            <span class="close-modal" onclick="closeModal()">&times;</span>
        </div>
        <form method="POST">
            <input type="hidden" name="withdrawal_id" id="withdrawalId">
            <input type="hidden" name="action" id="actionType">
            <div class="form-group">
                <label>Admin Notes</label>
                <textarea name="admin_notes" rows="3" placeholder="Add notes about this withdrawal..."></textarea>
            </div>
            <button type="submit" id="actionButton" class="btn-sm btn-approve" style="padding: 10px 20px;">Confirm</button>
            <button type="button" onclick="closeModal()" class="btn-sm" style="background: #94a3b8; color: white; padding: 10px 20px; margin-left: 10px;">Cancel</button>
        </form>
    </div>
</div>

<script>
function openActionModal(action, id) {
    document.getElementById('withdrawalId').value = id;
    document.getElementById('actionType').value = action;
    const modalTitle = document.getElementById('modalTitle');
    const actionButton = document.getElementById('actionButton');
    
    if (action === 'approve') {
        modalTitle.innerText = 'Approve Withdrawal';
        actionButton.innerText = 'Approve';
        actionButton.className = 'btn-sm btn-approve';
        actionButton.style.padding = '10px 20px';
    } else if (action === 'reject') {
        modalTitle.innerText = 'Reject Withdrawal';
        actionButton.innerText = 'Reject';
        actionButton.className = 'btn-sm btn-reject';
        actionButton.style.padding = '10px 20px';
    } else if (action === 'complete') {
        modalTitle.innerText = 'Mark as Completed';
        actionButton.innerText = 'Complete';
        actionButton.className = 'btn-sm btn-complete';
        actionButton.style.padding = '10px 20px';
    }
    document.getElementById('actionModal').style.display = 'flex';
}

function closeModal() {
    document.getElementById('actionModal').style.display = 'none';
}

window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
}
</script>

<?php
$content = ob_get_clean();
include 'layout.php';
?>

