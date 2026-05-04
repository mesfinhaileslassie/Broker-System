<?php
// user/legal_process.php - Legal process confirmation

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireLogin();

$conn = getDbConnection();
$transaction_id = intval($_GET['id'] ?? 0);
$user_id = $_SESSION['user_id'];

// Get transaction details
$transaction = $conn->query("
    SELECT t.*, l.title as listing_title, l.type as listing_type,
           u1.full_name as buyer_name, u1.id as buyer_id,
           u2.full_name as seller_name, u2.id as seller_id
    FROM transactions t
    JOIN listings l ON t.listing_id = l.id
    JOIN users u1 ON t.buyer_id = u1.id
    JOIN users u2 ON t.seller_id = u2.id
    WHERE t.id = $transaction_id AND (t.buyer_id = $user_id OR t.seller_id = $user_id)
")->fetch_assoc();

if (!$transaction) {
    header('Location: dashboard.php');
    exit;
}

$is_buyer = ($transaction['buyer_id'] == $user_id);
$is_seller = ($transaction['seller_id'] == $user_id);
$both_deposits_paid = ($transaction['status'] == 'deposits_complete');

$error = '';
$success = '';

// Handle legal document upload
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['upload_document'])) {
        $document_type = $_POST['document_type'];
        $notes = $_POST['notes'] ?? '';
        
        if (isset($_FILES['document']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../uploads/legal_docs/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $file_name = time() . '_' . $transaction_id . '_' . $user_id . '_' . basename($_FILES['document']['name']);
            $file_path = $upload_dir . $file_name;
            
            if (move_uploaded_file($_FILES['document']['tmp_name'], $file_path)) {
                $stmt = $conn->prepare("INSERT INTO legal_documents (transaction_id, user_id, document_type, file_path, notes) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("iisss", $transaction_id, $user_id, $document_type, $file_path, $notes);
                $stmt->execute();
                $success = "Document uploaded successfully!";
            } else {
                $error = "Failed to upload document";
            }
        } else {
            $error = "Please select a file to upload";
        }
    }
    
    // Handle legal confirmation
    if (isset($_POST['confirm_legal'])) {
        $legal_notes = $_POST['legal_notes'] ?? '';
        
        if ($is_buyer) {
            $conn->query("UPDATE transactions SET buyer_legal_confirmed = 1, legal_notes = CONCAT(legal_notes, '\n', 'Buyer confirmed: ', '$legal_notes') WHERE id = $transaction_id");
        } else {
            $conn->query("UPDATE transactions SET seller_legal_confirmed = 1, legal_notes = CONCAT(legal_notes, '\n', 'Seller confirmed: ', '$legal_notes') WHERE id = $transaction_id");
        }
        
        // Check if both confirmed
        $check = $conn->query("SELECT buyer_legal_confirmed, seller_legal_confirmed FROM transactions WHERE id = $transaction_id")->fetch_assoc();
        
        if ($check['buyer_legal_confirmed'] && $check['seller_legal_confirmed']) {
            // Both confirmed - release payment
            $release_amount = $transaction['total_amount'] - $transaction['commission_amount'];
            $conn->query("UPDATE users SET balance = balance + $release_amount WHERE id = {$transaction['seller_id']}");
            $conn->query("UPDATE users SET admin_balance = admin_balance - $release_amount WHERE role = 'admin'");
            $conn->query("UPDATE transactions SET status = 'completed', completed_at = NOW() WHERE id = $transaction_id");
            $success = "Legal process completed! Payment has been released to the seller.";
        } else {
            $success = "Your legal confirmation has been recorded. Waiting for the other party to confirm.";
        }
        
        header("Refresh: 2");
    }
}

// Get legal documents
$documents = $conn->query("
    SELECT d.*, u.full_name 
    FROM legal_documents d
    JOIN users u ON d.user_id = u.id
    WHERE d.transaction_id = $transaction_id
    ORDER BY d.uploaded_at DESC
");

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Legal Process - Transaction #<?php echo $transaction_id; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f5f6fa; }
        .header { background: white; box-shadow: 0 2px 10px rgba(0,0,0,0.1); padding: 16px 24px; }
        .header-content { max-width: 1200px; margin: 0 auto; display: flex; justify-content: space-between; align-items: center; }
        .logo { font-size: 24px; font-weight: 700; color: #667eea; text-decoration: none; }
        .container { max-width: 1000px; margin: 40px auto; padding: 0 24px; }
        
        .card { background: white; border-radius: 12px; padding: 24px; margin-bottom: 24px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card h2 { font-size: 18px; margin-bottom: 16px; color: #333; display: flex; align-items: center; gap: 8px; }
        
        .confirmation-status { display: flex; gap: 20px; margin-bottom: 20px; flex-wrap: wrap; }
        .status-badge { flex: 1; padding: 16px; border-radius: 8px; text-align: center; }
        .status-badge.completed { background: #d4edda; color: #155724; }
        .status-badge.pending { background: #fff3cd; color: #856404; }
        .status-badge i { font-size: 24px; margin-bottom: 8px; display: block; }
        
        .form-group { margin-bottom: 16px; }
        label { display: block; margin-bottom: 8px; font-weight: 500; color: #333; }
        input, select, textarea { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px; font-family: inherit; }
        .btn { padding: 12px 24px; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; }
        .btn-primary { background: #667eea; color: white; }
        .btn-success { background: #28a745; color: white; }
        .btn-warning { background: #ffc107; color: #333; }
        
        .document-list { margin-top: 16px; }
        .document-item { background: #f8f9fa; padding: 12px; border-radius: 8px; margin-bottom: 8px; display: flex; justify-content: space-between; align-items: center; }
        .error-message { background: #f8d7da; color: #721c24; padding: 12px; border-radius: 8px; margin-bottom: 16px; }
        .success-message { background: #d4edda; color: #155724; padding: 12px; border-radius: 8px; margin-bottom: 16px; }
        
        .legal-steps { display: flex; justify-content: space-between; margin: 30px 0; flex-wrap: wrap; }
        .step { flex: 1; text-align: center; padding: 16px; position: relative; }
        .step.active { color: #667eea; }
        .step.completed { color: #28a745; }
        .step .step-number { width: 30px; height: 30px; background: #e0e0e0; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 8px; }
        .step.completed .step-number { background: #28a745; color: white; }
        .step.active .step-number { background: #667eea; color: white; }
        
        @media (max-width: 768px) {
            .legal-steps { flex-direction: column; gap: 16px; }
            .confirmation-status { flex-direction: column; }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <a href="/broker_system/index.php" class="logo">🏪 Ethio Brokerplace</a>
            <a href="transaction.php?id=<?php echo $transaction_id; ?>" style="color: #666;"><i class="fas fa-arrow-left"></i> Back to Transaction</a>
        </div>
    </header>
    
    <div class="container">
        <!-- Legal Process Steps -->
        <div class="card">
            <h2><i class="fas fa-gavel"></i> Legal Process</h2>
            <div class="legal-steps">
                <div class="step <?php echo $both_deposits_paid ? 'completed' : ''; ?>">
                    <div class="step-number">1</div>
                    <div>Deposits Paid</div>
                </div>
                <div class="step <?php echo ($transaction['buyer_legal_confirmed'] || $transaction['seller_legal_confirmed']) ? 'active' : ''; ?>">
                    <div class="step-number">2</div>
                    <div>Legal Documentation</div>
                </div>
                <div class="step <?php echo ($transaction['buyer_legal_confirmed'] && $transaction['seller_legal_confirmed']) ? 'completed' : ''; ?>">
                    <div class="step-number">3</div>
                    <div>Both Confirm</div>
                </div>
                <div class="step <?php echo $transaction['status'] == 'completed' ? 'completed' : ''; ?>">
                    <div class="step-number">4</div>
                    <div>Payment Released</div>
                </div>
            </div>
        </div>
        
        <?php if ($error): ?>
            <div class="error-message"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="success-message"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        
        <!-- Documents Section -->
        <div class="card">
            <h2><i class="fas fa-file-upload"></i> Legal Documents</h2>
            <p>Upload contracts, agreements, or other legal documents related to this transaction.</p>
            
            <form method="POST" enctype="multipart/form-data" style="margin: 20px 0;">
                <div class="form-group">
                    <label>Document Type</label>
                    <select name="document_type" required>
                        <option value="contract">Contract/Agreement</option>
                        <option value="id_proof">ID Proof</option>
                        <option value="property_doc">Property Document</option>
                        <option value="payment_proof">Payment Proof</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Document File (PDF, JPG, PNG)</label>
                    <input type="file" name="document" accept=".pdf,.jpg,.jpeg,.png" required>
                </div>
                <div class="form-group">
                    <label>Notes (Optional)</label>
                    <textarea name="notes" rows="2" placeholder="Add any notes about this document..."></textarea>
                </div>
                <button type="submit" name="upload_document" class="btn btn-primary"><i class="fas fa-upload"></i> Upload Document</button>
            </form>
            
            <?php if ($documents->num_rows > 0): ?>
                <div class="document-list">
                    <h3>Uploaded Documents</h3>
                    <?php while($doc = $documents->fetch_assoc()): ?>
                        <div class="document-item">
                            <div>
                                <strong><?php echo ucfirst($doc['document_type']); ?></strong><br>
                                <small>Uploaded by: <?php echo htmlspecialchars($doc['full_name']); ?> on <?php echo date('M d, Y', strtotime($doc['uploaded_at'])); ?></small>
                            </div>
                            <a href="<?php echo $doc['file_path']; ?>" target="_blank" class="btn btn-primary" style="padding: 6px 12px; font-size: 12px;">View</a>
                        </div>
                    <?php endwhile; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Legal Confirmation -->
        <div class="card">
            <h2><i class="fas fa-check-circle"></i> Legal Confirmation</h2>
            
            <div class="confirmation-status">
                <div class="status-badge <?php echo $transaction['buyer_legal_confirmed'] ? 'completed' : 'pending'; ?>">
                    <i class="fas fa-user"></i>
                    <strong>Buyer Status</strong>
                    <?php if ($transaction['buyer_legal_confirmed']): ?>
                        <span>✓ Legal process confirmed</span>
                    <?php else: ?>
                        <span>⏳ Waiting for confirmation</span>
                    <?php endif; ?>
                </div>
                <div class="status-badge <?php echo $transaction['seller_legal_confirmed'] ? 'completed' : 'pending'; ?>">
                    <i class="fas fa-store"></i>
                    <strong>Seller Status</strong>
                    <?php if ($transaction['seller_legal_confirmed']): ?>
                        <span>✓ Legal process confirmed</span>
                    <?php else: ?>
                        <span>⏳ Waiting for confirmation</span>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if (!$transaction['buyer_legal_confirmed'] && $is_buyer): ?>
                <div class="form-group">
                    <label>Legal Process Confirmation</label>
                    <textarea name="legal_notes" form="confirmForm" rows="3" placeholder="Confirm that all legal processes, documentations, and requirements have been completed..." required></textarea>
                </div>
                <form method="POST" id="confirmForm">
                    <input type="hidden" name="confirm_legal" value="1">
                    <button type="submit" class="btn btn-success" onclick="return confirm('Are you sure all legal processes are completed? This will confirm your acceptance.')">
                        <i class="fas fa-check-circle"></i> I Confirm All Legal Processes Are Completed
                    </button>
                </form>
            <?php elseif (!$transaction['seller_legal_confirmed'] && $is_seller): ?>
                <div class="form-group">
                    <label>Legal Process Confirmation</label>
                    <textarea name="legal_notes" form="confirmForm" rows="3" placeholder="Confirm that all legal processes, documentations, and requirements have been completed..." required></textarea>
                </div>
                <form method="POST" id="confirmForm">
                    <input type="hidden" name="confirm_legal" value="1">
                    <button type="submit" class="btn btn-success" onclick="return confirm('Are you sure all legal processes are completed? This will confirm your acceptance.')">
                        <i class="fas fa-check-circle"></i> I Confirm All Legal Processes Are Completed
                    </button>
                </form>
            <?php elseif ($transaction['buyer_legal_confirmed'] && $transaction['seller_legal_confirmed']): ?>
                <div class="success-message">
                    <i class="fas fa-check-circle"></i> Both parties have confirmed the legal process completion. Payment will be released.
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Transaction Summary -->
        <div class="card">
            <h2><i class="fas fa-info-circle"></i> Transaction Summary</h2>
            <div class="info-grid" style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px;">
                <div><strong>Item:</strong> <?php echo htmlspecialchars($transaction['listing_title']); ?></div>
                <div><strong>Total Amount:</strong> <?php echo formatMoney($transaction['total_amount']); ?></div>
                <div><strong>Buyer:</strong> <?php echo htmlspecialchars($transaction['buyer_name']); ?></div>
                <div><strong>Seller:</strong> <?php echo htmlspecialchars($transaction['seller_name']); ?></div>
                <div><strong>Status:</strong> <?php echo getStatusBadge($transaction['status']); ?></div>
                <div><strong>Legal Confirmation:</strong> 
                    <?php if ($transaction['buyer_legal_confirmed'] && $transaction['seller_legal_confirmed']): ?>
                        <span class="badge badge-success">Both Confirmed ✓</span>
                    <?php elseif ($transaction['buyer_legal_confirmed']): ?>
                        <span class="badge badge-info">Buyer Confirmed</span>
                    <?php elseif ($transaction['seller_legal_confirmed']): ?>
                        <span class="badge badge-info">Seller Confirmed</span>
                    <?php else: ?>
                        <span class="badge badge-warning">Pending</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>