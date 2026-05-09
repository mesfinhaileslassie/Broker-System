<?php
// user/transaction_actions.php - Handle all transaction button actions

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/escrow_functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Please login']);
    exit;
}

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';
$transaction_id = intval($input['transaction_id'] ?? 0);
$notes = $input['notes'] ?? '';

if (!$transaction_id) {
    echo json_encode(['success' => false, 'error' => 'Transaction ID required']);
    exit;
}

// Get transaction details
$transaction = $conn->query("
    SELECT t.*, l.title, l.type, l.seller_id, l.price,
           u1.full_name as buyer_name, u2.full_name as seller_name
    FROM transactions t
    JOIN listings l ON t.listing_id = l.id
    JOIN users u1 ON t.buyer_id = u1.id
    JOIN users u2 ON t.seller_id = u2.id
    WHERE t.id = $transaction_id
")->fetch_assoc();

if (!$transaction) {
    echo json_encode(['success' => false, 'error' => 'Transaction not found']);
    exit;
}

$is_buyer = ($transaction['buyer_id'] == $user_id);
$is_seller = ($transaction['seller_id'] == $user_id);
$is_admin = ($_SESSION['user_role'] == 'admin');

$response = ['success' => false, 'error' => 'Invalid action'];

switch($action) {
    // ==================== BUYER/TENANT ACTIONS ====================
    
    case 'pay_full_amount':
        if (!$is_buyer) {
            $response = ['success' => false, 'error' => 'Only buyer can pay'];
            break;
        }
        
        $remaining = $transaction['total_amount'] - $transaction['deposit_amount'];
        if ($remaining <= 0) {
            $response = ['success' => false, 'error' => 'No remaining amount to pay'];
            break;
        }
        
        // Generate payment code for remaining amount
        do {
            $payment_code = str_pad(random_int(0, 99999), 5, '0', STR_PAD_LEFT);
            $code_check = $conn->query("SELECT id FROM payment_codes WHERE code = '$payment_code'");
        } while ($code_check->num_rows > 0);
        
        $expires_at = date('Y-m-d H:i:s', strtotime('+30 minutes'));
        $stmt = $conn->prepare("
            INSERT INTO payment_codes (code, transaction_id, amount, user_id, type, expires_at, status, created_at) 
            VALUES (?, ?, ?, ?, 'full_payment', ?, 'pending', NOW())
        ");
        $stmt->bind_param("siids", $payment_code, $transaction_id, $remaining, $user_id, $expires_at);
        $stmt->execute();
        
        $response = [
            'success' => true, 
            'message' => 'Payment code generated',
            'payment_code' => $payment_code,
            'amount' => $remaining
        ];
        break;
        
    case 'confirm_receipt':
        if (!$is_buyer) {
            $response = ['success' => false, 'error' => 'Only buyer can confirm'];
            break;
        }
        
        if ($transaction['delivery_status'] != 'delivered') {
            $response = ['success' => false, 'error' => 'Seller has not marked delivery yet'];
            break;
        }
        
        $result = releaseEscrowPayment($conn, $transaction_id, $user_id, 'buyer', $notes);
        $response = $result;
        break;
        
    case 'cancel_transaction':
        if (!$is_buyer && !$is_seller) {
            $response = ['success' => false, 'error' => 'Unauthorized'];
            break;
        }
        
        $conn->query("
            UPDATE transactions 
            SET status = 'cancelled', 
                cancelled_by = $user_id,
                cancelled_at = NOW(),
                updated_at = NOW()
            WHERE id = $transaction_id
        ");
        
        // Cancel escrow and refund
        if ($transaction['escrow_held'] > 0) {
            refundEscrowPayment($conn, $transaction_id, $user_id, "Cancelled by user");
        }
        
        $response = ['success' => true, 'message' => 'Transaction cancelled'];
        break;
        
    // ==================== SELLER/LANDLORD ACTIONS ====================
    
    case 'approve_booking':
        if (!$is_seller) {
            $response = ['success' => false, 'error' => 'Only seller can approve'];
            break;
        }
        
        $conn->query("
            UPDATE transactions 
            SET status = 'approved', 
                approved_at = NOW(),
                updated_at = NOW()
            WHERE id = $transaction_id
        ");
        
        addTransactionTimeline($conn, $transaction_id, 'booking_approved', 
            "Booking approved by seller", $user_id);
        
        $response = ['success' => true, 'message' => 'Booking approved! Waiting for payment.'];
        break;
        
    case 'reject_booking':
        if (!$is_seller) {
            $response = ['success' => false, 'error' => 'Only seller can reject'];
            break;
        }
        
        $conn->query("
            UPDATE transactions 
            SET status = 'rejected', 
                rejection_reason = '$notes',
                updated_at = NOW()
            WHERE id = $transaction_id
        ");
        
        $response = ['success' => true, 'message' => 'Booking rejected'];
        break;
        
    case 'confirm_handover':
        if (!$is_seller) {
            $response = ['success' => false, 'error' => 'Only seller can confirm handover'];
            break;
        }
        
        $conn->query("
            UPDATE transactions 
            SET handover_confirmed = 1,
                handover_confirmed_at = NOW(),
                delivery_status = 'handed_over',
                updated_at = NOW()
            WHERE id = $transaction_id
        ");
        
        addTransactionTimeline($conn, $transaction_id, 'handover_confirmed', 
            "Property handover confirmed by seller", $user_id);
        
        $response = ['success' => true, 'message' => 'Handover confirmed! Waiting for buyer confirmation.'];
        break;
        
    case 'mark_delivered':
        if (!$is_seller) {
            $response = ['success' => false, 'error' => 'Only seller can mark delivery'];
            break;
        }
        
        $result = markDelivery($conn, $transaction_id, $user_id, $notes);
        $response = $result;
        break;
        
    case 'upload_delivery_proof':
        if (!$is_seller) {
            $response = ['success' => false, 'error' => 'Only seller can upload proof'];
            break;
        }
        
        // Handle file upload
        if (isset($_FILES['proof_file']) && $_FILES['proof_file']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../uploads/delivery_proofs/';
            if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
            
            $filename = time() . '_' . $transaction_id . '_' . basename($_FILES['proof_file']['name']);
            $target_file = $upload_dir . $filename;
            
            if (move_uploaded_file($_FILES['proof_file']['tmp_name'], $target_file)) {
                $stmt = $conn->prepare("
                    INSERT INTO delivery_proofs (transaction_id, user_id, file_path, proof_text, uploaded_at) 
                    VALUES (?, ?, ?, ?, NOW())
                ");
                $stmt->bind_param("iiss", $transaction_id, $user_id, $filename, $notes);
                $stmt->execute();
                
                $response = ['success' => true, 'message' => 'Delivery proof uploaded'];
            } else {
                $response = ['success' => false, 'error' => 'Failed to upload file'];
            }
        } else {
            $response = ['success' => false, 'error' => 'Please select a file to upload'];
        }
        break;
        
    // ==================== EMPLOYER ACTIONS ====================
    
    case 'hire_candidate':
        $job_id = intval($input['job_id'] ?? 0);
        $applicant_id = intval($input['applicant_id'] ?? 0);
        
        if (!$is_seller) {
            $response = ['success' => false, 'error' => 'Only job poster can hire'];
            break;
        }
        
        // Update job application
        $conn->query("
            UPDATE job_applications 
            SET status = 'hired', hired_at = NOW()
            WHERE job_id = $job_id AND applicant_id = $applicant_id
        ");
        
        // Create transaction if not exists
        $check = $conn->query("SELECT id FROM transactions WHERE listing_id = $job_id AND buyer_id = $applicant_id");
        if ($check->num_rows == 0) {
            $stmt = $conn->prepare("
                INSERT INTO transactions (listing_id, buyer_id, seller_id, total_amount, deposit_amount, commission_amount, remaining_balance, status, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, 'hired', NOW())
            ");
            $stmt->bind_param("iiidddd", $job_id, $applicant_id, $user_id, $transaction['price'], 
                $transaction['price'] * 0.3, $transaction['price'] * 0.15, $transaction['price'] * 0.55);
            $stmt->execute();
            $transaction_id = $conn->insert_id;
        }
        
        addTransactionTimeline($conn, $transaction_id, 'hired', 
            "Candidate hired for job", $user_id);
        
        $response = ['success' => true, 'message' => 'Candidate hired! Please fund escrow to start.'];
        break;
        
    case 'fund_escrow':
        if (!$is_seller) {
            $response = ['success' => false, 'error' => 'Only employer can fund escrow'];
            break;
        }
        
        // Generate payment code for escrow funding
        do {
            $payment_code = str_pad(random_int(0, 99999), 5, '0', STR_PAD_LEFT);
            $code_check = $conn->query("SELECT id FROM payment_codes WHERE code = '$payment_code'");
        } while ($code_check->num_rows > 0);
        
        $expires_at = date('Y-m-d H:i:s', strtotime('+30 minutes'));
        $stmt = $conn->prepare("
            INSERT INTO payment_codes (code, transaction_id, amount, user_id, type, expires_at, status, created_at) 
            VALUES (?, ?, ?, ?, 'escrow_fund', ?, 'pending', NOW())
        ");
        $stmt->bind_param("siids", $payment_code, $transaction_id, $transaction['total_amount'], $user_id, $expires_at);
        $stmt->execute();
        
        $response = [
            'success' => true,
            'message' => 'Escrow funding code generated',
            'payment_code' => $payment_code,
            'amount' => $transaction['total_amount']
        ];
        break;
        
    case 'approve_work':
        if (!$is_seller) {
            $response = ['success' => false, 'error' => 'Only employer can approve work'];
            break;
        }
        
        if ($transaction['work_submitted_at'] == '0000-00-00 00:00:00' || !$transaction['work_submitted_at']) {
            $response = ['success' => false, 'error' => 'No work has been submitted yet'];
            break;
        }
        
        $conn->query("
            UPDATE transactions 
            SET work_approved_at = NOW(),
                status = 'work_approved',
                updated_at = NOW()
            WHERE id = $transaction_id
        ");
        
        // Release payment to worker
        $result = releaseEscrowPayment($conn, $transaction_id, $user_id, 'employer', 'Work approved');
        $response = $result;
        break;
        
    case 'reject_work':
        if (!$is_seller) {
            $response = ['success' => false, 'error' => 'Only employer can reject work'];
            break;
        }
        
        $conn->query("
            UPDATE transactions 
            SET work_rejected_at = NOW(),
                rejection_reason = '$notes',
                status = 'work_rejected',
                updated_at = NOW()
            WHERE id = $transaction_id
        ");
        
        $response = ['success' => true, 'message' => 'Work rejected. Worker can resubmit.'];
        break;
        
    // ==================== WORKER ACTIONS ====================
    
    case 'accept_job':
        if (!$is_buyer) {
            $response = ['success' => false, 'error' => 'Only worker can accept job'];
            break;
        }
        
        $conn->query("
            UPDATE transactions 
            SET status = 'accepted',
                accepted_at = NOW(),
                updated_at = NOW()
            WHERE id = $transaction_id AND buyer_id = $user_id
        ");
        
        addTransactionTimeline($conn, $transaction_id, 'job_accepted', 
            "Worker accepted the job", $user_id);
        
        $response = ['success' => true, 'message' => 'Job accepted! Waiting for employer to fund escrow.'];
        break;
        
    case 'submit_work':
        if (!$is_buyer) {
            $response = ['success' => false, 'error' => 'Only worker can submit work'];
            break;
        }
        
        $work_link = $input['work_link'] ?? '';
        $work_description = $input['work_description'] ?? '';
        
        $conn->query("
            UPDATE transactions 
            SET work_submitted_at = NOW(),
                work_link = '$work_link',
                work_description = '$work_description',
                status = 'work_submitted',
                updated_at = NOW()
            WHERE id = $transaction_id AND buyer_id = $user_id
        ");
        
        addTransactionTimeline($conn, $transaction_id, 'work_submitted', 
            "Work submitted for review", $user_id);
        
        $response = ['success' => true, 'message' => 'Work submitted! Waiting for employer approval.'];
        break;
        
    case 'mark_completed':
        if (!$is_buyer && !$is_seller) {
            $response = ['success' => false, 'error' => 'Unauthorized'];
            break;
        }
        
        $conn->query("
            UPDATE transactions 
            SET worker_completed = 1,
                worker_completed_at = NOW(),
                updated_at = NOW()
            WHERE id = $transaction_id AND buyer_id = $user_id
        ");
        
        // Check if both parties completed
        $check = $conn->query("
            SELECT worker_completed, employer_completed 
            FROM transactions WHERE id = $transaction_id
        ")->fetch_assoc();
        
        if ($check['worker_completed'] && $check['employer_completed']) {
            $result = releaseEscrowPayment($conn, $transaction_id, $user_id, 'both', 'Both parties confirmed completion');
            $response = $result;
        } else {
            $response = ['success' => true, 'message' => 'Marked as completed. Waiting for other party.'];
        }
        break;
        
    case 'request_payment':
        if (!$is_buyer) {
            $response = ['success' => false, 'error' => 'Only worker can request payment'];
            break;
        }
        
        $conn->query("
            UPDATE transactions 
            SET payment_requested_at = NOW(),
                status = 'payment_requested',
                updated_at = NOW()
            WHERE id = $transaction_id AND buyer_id = $user_id
        ");
        
        addTransactionTimeline($conn, $transaction_id, 'payment_requested', 
            "Worker requested payment release", $user_id);
        
        $response = ['success' => true, 'message' => 'Payment request sent to employer!'];
        break;
        
    // ==================== COMMON ACTIONS ====================
    
    case 'view_timeline':
        $timeline = getTransactionTimeline($conn, $transaction_id);
        $timeline_data = [];
        while ($row = $timeline->fetch_assoc()) {
            $timeline_data[] = $row;
        }
        $response = ['success' => true, 'timeline' => $timeline_data];
        break;
        
    case 'view_escrow_status':
        $escrow_status = getTransactionEscrowStatus($conn, $transaction_id);
        $response = ['success' => true, 'escrow' => $escrow_status];
        break;
        
    default:
        $response = ['success' => false, 'error' => 'Unknown action'];
}

$conn->close();
echo json_encode($response);
?>