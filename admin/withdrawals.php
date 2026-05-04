<?php
// admin/withdrawals.php - Complete working version

require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

requireAdminLogin();

$conn = getDbConnection();
$message = '';
$error = '';

// Handle withdrawal actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $withdrawalId = intval($_POST['withdrawal_id'] ?? 0);
    $adminId = $_SESSION['admin_id'];
    $adminNotes = $conn->real_escape_string($_POST['admin_notes'] ?? '');
    
    if (isset($_POST['approve'])) {
        $stmt = $conn->prepare("UPDATE withdrawal_requests SET status = 'approved', admin_notes = ?, processed_by = ?, processed_at = NOW() WHERE id = ?");
        $stmt->bind_param("sii", $adminNotes, $adminId, $withdrawalId);
        if ($stmt->execute()) {
            $message = "Withdrawal approved successfully";
        } else {
            $error = "Failed to approve withdrawal";
        }
    }
    
    if (isset($_POST['reject'])) {
        $stmt = $conn->prepare("UPDATE withdrawal_requests SET status = 'rejected', admin_notes = ?, processed_by = ?, processed_at = NOW() WHERE id = ?");
        $stmt->bind_param("sii", $adminNotes, $adminId, $withdrawalId);
        if ($stmt->execute()) {
            // Refund the amount back to user balance
            $withdrawal = $conn->query("SELECT user_id, amount FROM withdrawal_requests WHERE id = $withdrawalId")->fetch_assoc();
            if ($withdrawal) {
                $conn->query("UPDATE users SET balance = balance + {$withdrawal['amount']} WHERE id = {$withdrawal['user_id']}");
            }
            $message = "Withdrawal rejected and amount refunded";
        } else {
            $error = "Failed to reject withdrawal";
        }
    }
    
    if (isset($_POST['complete'])) {
        $stmt = $conn->prepare("UPDATE withdrawal_requests SET status = 'completed', processed_by = ?, processed_at = NOW() WHERE id = ?");
        $stmt->bind_param("ii", $adminId, $withdrawalId);
        if ($stmt->execute()) {
            $message = "Withdrawal marked as completed";
        } else {
            $error = "Failed to mark as completed";
        }
    }
}

// Get withdrawals with filters
$status = $_GET['status'] ?? '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$where = $status ? "WHERE w.status = '$status'" : "";
$sql = "SELECT w.*, u.full_name, u.email, u.phone, u.balance
        FROM withdrawal_requests w
        JOIN users u ON w.user_id = u.id
        $where
        ORDER BY 
            FIELD(w.status, 'pending', 'approved', 'completed', 'rejected'),
            w.created_at DESC
        LIMIT $offset, $limit";
$withdrawals = $conn->query($sql);

// Get total count
$totalResult = $conn->query("SELECT COUNT(*) as count FROM withdrawal_requests w $where");
$total = $totalResult ? $totalResult->fetch_assoc()['count'] : 0;
$totalPages = $total > 0 ? ceil($total / $limit) : 1;

// Get statistics
$pendingResult = $conn->query("SELECT SUM(amount) as total, COUNT(*) as count FROM withdrawal_requests WHERE status = 'pending'");
$pending = $pendingResult ? $pendingResult->fetch_assoc() : ['total' => 0, 'count' => 0];

$approvedResult = $conn->query("SELECT SUM(amount) as total, COUNT(*) as count FROM withdrawal_requests WHERE status = 'approved'");
$approved = $approvedResult ? $approvedResult->fetch_assoc() : ['total' => 0, 'count' => 0];

$completedResult = $conn->query("SELECT SUM(amount) as total, COUNT(*) as count FROM withdrawal_requests WHERE status = 'completed'");
$completed = $completedResult ? $completedResult->fetch_assoc() : ['total' => 0, 'count' => 0];

$totalProcessedResult = $conn->query("SELECT SUM(amount) as total FROM withdrawal_requests WHERE status IN ('approved', 'completed')");
$totalProcessed = $totalProcessedResult ? ($totalProcessedResult->fetch_assoc()['total'] ?? 0) : 0;

$minWithdrawal = getSetting('min_withdrawal', 100);
$maxWithdrawal = getSetting('max_withdrawal', 100000);

$stats = [
    'pending' => $pending,
    'approved' => $approved,
    'completed' => $completed,
    'total_processed' => $totalProcessed,
];

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Withdrawals - Admin Panel</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f5f6fa; }
        .sidebar { width: 260px; background: #1a1a2e; color: white; height: 100vh; position: fixed; overflow-y: auto; }
        .sidebar-header { padding: 24px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-header h2 { font-size: 20px; }
        .sidebar-header p { font-size: 12px; color: #888; margin-top: 8px; }
        .nav-menu { list-style: none; padding: 20px 0; }
        .nav-item { padding: 12px 24px; display: flex; align-items: center; gap: 12px; color: #aaa; cursor: pointer; transition: all 0.3s; }
        .nav-item:hover, .nav-item.active { background: rgba(255,255,255,0.1); color: white; }
        .nav-item i { width: 20px; }
        .main-content { margin-left: 260px; padding: 24px; }
        .top-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
        .page-title { font-size: 28px; font-weight: 600; }
        .logout-btn { padding: 8px 16px; background: #e74c3c; color: white; border-radius: 6px; text-decoration: none; }
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 24px; }
        .stat-card { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .stat-card .value { font-size: 28px; font-weight: 700; }
        .stat-card .label { color: #666; font-size: 14px; margin-top: 5px; }
        .stat-card small { font-size: 12px; color: #888; }
        .section { background: white; border-radius: 12px; padding: 20px; margin-bottom: 24px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .section-title { font-size: 18px; font-weight: 600; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 2px solid #f0f0f0; }
        .filters { display: flex; gap: 12px; margin-bottom: 20px; }
        .filter-select { padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; }
        th { font-weight: 600; color: #666; font-size: 13px; }
        .badge { padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 500; display: inline-block; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-info { background: #d1ecf1; color: #0c5460; }
        .badge-success { background: #d4edda; color: #155724; }
        .badge-danger { background: #f8d7da; color: #721c24; }
        .btn-sm { padding: 4px 10px; font-size: 12px; border-radius: 4px; border: none; cursor: pointer; margin: 2px; }
        .btn-approve { background: #28a745; color: white; }
        .btn-reject { background: #dc3545; color: white; }
        .btn-complete { background: #17a2b8; color: white; }
        .message { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; }
        .message-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .message-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .info-box { background: #e3f2fd; padding: 12px; border-radius: 8px; margin-bottom: 20px; }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); align-items: center; justify-content: center; z-index: 1000; }
        .modal-content { background: white; border-radius: 12px; padding: 24px; width: 500px; max-width: 90%; }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; margin-bottom: 6px; font-weight: 500; }
        .form-group textarea { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px; font-family: inherit; }
        .pagination { display: flex; justify-content: center; gap: 8px; margin-top: 20px; }
        .pagination a, .pagination span { padding: 8px 12px; background: white; border: 1px solid #ddd; border-radius: 6px; text-decoration: none; color: #333; }
        .pagination .active { background: #667eea; color: white; }
    </style>
</head>
<body>
    <div class="admin-wrapper">
        <div class="sidebar">
            <div class="sidebar-header">
                <h2>🏪 Brokerplace</h2>
                <p>Admin Dashboard</p>
            </div>
            <ul class="nav-menu">
                <li class="nav-item" onclick="location.href='dashboard.php'"><i class="fas fa-tachometer-alt"></i> Dashboard</li>
                <li class="nav-item" onclick="location.href='users.php'"><i class="fas fa-users"></i> Users</li>
                <li class="nav-item" onclick="location.href='companies.php'"><i class="fas fa-building"></i> Companies</li>
                <li class="nav-item" onclick="location.href='transactions.php'"><i class="fas fa-exchange-alt"></i> Transactions</li>
                <li class="nav-item" onclick="location.href='disputes.php'"><i class="fas fa-gavel"></i> Disputes</li>
                <li class="nav-item" onclick="location.href='payments.php'"><i class="fas fa-credit-card"></i> Payments</li>
                <li class="nav-item" onclick="location.href='analytics.php'"><i class="fas fa-chart-line"></i> Analytics</li>
                <li class="nav-item" onclick="location.href='messages.php'"><i class="fas fa-envelope"></i> Messages</li>
                <li class="nav-item" onclick="location.href='tickets.php'"><i class="fas fa-ticket-alt"></i> Support</li>
                <li class="nav-item active"><i class="fas fa-money-bill-wave"></i> Withdrawals</li>
                <li class="nav-item" onclick="location.href='settings.php'"><i class="fas fa-cog"></i> Settings</li>
            </ul>
        </div>
        
        <div class="main-content">
            <div class="top-header">
                <h1 class="page-title">Withdrawal Requests</h1>
                <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
            
            <?php if ($message): ?>
                <div class="message message-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="message message-error"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="value"><?php echo formatMoney($stats['pending']['total'] ?? 0); ?></div>
                    <div class="label">Pending Amount</div>
                    <small><?php echo number_format($stats['pending']['count'] ?? 0); ?> requests</small>
                </div>
                <div class="stat-card">
                    <div class="value"><?php echo formatMoney($stats['approved']['total'] ?? 0); ?></div>
                    <div class="label">Approved Amount</div>
                    <small><?php echo number_format($stats['approved']['count'] ?? 0); ?> requests</small>
                </div>
                <div class="stat-card">
                    <div class="value"><?php echo formatMoney($stats['completed']['total'] ?? 0); ?></div>
                    <div class="label">Completed Amount</div>
                </div>
                <div class="stat-card">
                    <div class="value"><?php echo formatMoney($stats['total_processed']); ?></div>
                    <div class="label">Total Processed</div>
                </div>
            </div>
            
            <div class="info-box">
                <i class="fas fa-info-circle"></i> Withdrawal limits: Min <?php echo formatMoney($minWithdrawal); ?> | Max <?php echo formatMoney($maxWithdrawal); ?>
            </div>
            
            <div class="filters">
                <select class="filter-select" onchange="location.href='?status='+this.value">
                    <option value="">All Status</option>
                    <option value="pending" <?php echo $status == 'pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="approved" <?php echo $status == 'approved' ? 'selected' : ''; ?>>Approved</option>
                    <option value="completed" <?php echo $status == 'completed' ? 'selected' : ''; ?>>Completed</option>
                    <option value="rejected" <?php echo $status == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                </select>
            </div>
            
            <div class="section">
                <div class="section-title">Withdrawal Requests</div>
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>User</th>
                                <th>Amount</th>
                                <th>Bank Details</th>
                                <th>Status</th>
                                <th>Requested</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($withdrawals && $withdrawals->num_rows > 0): ?>
                                <?php while($row = $withdrawals->fetch_assoc()): ?>
                                    <tr>
                                        <td>#<?php echo $row['id']; ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($row['full_name']); ?></strong><br>
                                            <small><?php echo htmlspecialchars($row['email']); ?></small>
                                        </td>
                                        <td><strong><?php echo formatMoney($row['amount']); ?></strong></td>
                                        <td>
                                            <?php echo htmlspecialchars($row['bank_name'] ?? 'N/A'); ?><br>
                                            <small><?php echo htmlspecialchars($row['account_number'] ?? 'N/A'); ?></small>
                                        </td>
                                        <td>
                                            <?php
                                            $badgeClass = match($row['status']) {
                                                'pending' => 'badge-warning',
                                                'approved' => 'badge-info',
                                                'completed' => 'badge-success',
                                                'rejected' => 'badge-danger',
                                                default => 'badge-secondary'
                                            };
                                            ?>
                                            <span class="badge <?php echo $badgeClass; ?>"><?php echo ucfirst($row['status']); ?></span>
                                        </td>
                                        <td><?php echo date('M d, H:i', strtotime($row['created_at'])); ?></td>
                                        <td>
                                            <?php if ($row['status'] == 'pending'): ?>
                                                <button onclick="openActionModal('approve', <?php echo $row['id']; ?>, <?php echo $row['amount']; ?>, '<?php echo htmlspecialchars($row['full_name']); ?>')" class="btn-sm btn-approve">Approve</button>
                                                <button onclick="openActionModal('reject', <?php echo $row['id']; ?>, <?php echo $row['amount']; ?>, '<?php echo htmlspecialchars($row['full_name']); ?>')" class="btn-sm btn-reject">Reject</button>
                                            <?php elseif ($row['status'] == 'approved'): ?>
                                                <button onclick="completeWithdrawal(<?php echo $row['id']; ?>)" class="btn-sm btn-complete">Mark Complete</button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" style="text-align: center;">No withdrawal requests found</td>
                                </tr>
                            <?php endif; ?>
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
        </div>
    </div>
    
    <!-- Action Modal -->
    <div id="actionModal" class="modal">
        <div class="modal-content">
            <h3 id="modalTitle">Process Withdrawal</h3>
            <form method="POST">
                <input type="hidden" name="withdrawal_id" id="withdrawalId">
                <div class="form-group">
                    <label>Amount: <span id="withdrawalAmount"></span></label>
                </div>
                <div class="form-group">
                    <label>User: <span id="userName"></span></label>
                </div>
                <div class="form-group">
                    <label>Admin Notes</label>
                    <textarea name="admin_notes" rows="3" placeholder="Add any notes about this withdrawal..."></textarea>
                </div>
                <div class="form-group" style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" onclick="closeModal()" class="btn-sm" style="background:#6c757d; color:white;">Cancel</button>
                    <button type="submit" id="actionButton" class="btn-sm">Confirm</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        let currentAction = '';
        
        function openActionModal(action, id, amount, userName) {
            currentAction = action;
            document.getElementById('withdrawalId').value = id;
            document.getElementById('withdrawalAmount').innerText = amount.toFixed(2) + ' ETB';
            document.getElementById('userName').innerText = userName;
            
            const modalTitle = document.getElementById('modalTitle');
            const actionButton = document.getElementById('actionButton');
            
            if (action === 'approve') {
                modalTitle.innerText = 'Approve Withdrawal';
                actionButton.innerText = 'Approve';
                actionButton.name = 'approve';
                actionButton.style.background = '#28a745';
            } else {
                modalTitle.innerText = 'Reject Withdrawal';
                actionButton.innerText = 'Reject';
                actionButton.name = 'reject';
                actionButton.style.background = '#dc3545';
            }
            
            document.getElementById('actionModal').style.display = 'flex';
        }
        
        function completeWithdrawal(id) {
            if (confirm('Mark this withdrawal as completed? The user will be notified.')) {
                let form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = '<input type="hidden" name="withdrawal_id" value="' + id + '"><input type="hidden" name="complete" value="1">';
                document.body.appendChild(form);
                form.submit();
            }
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
</body>
</html>