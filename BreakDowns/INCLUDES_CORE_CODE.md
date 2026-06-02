BRS/includes/AvailabilityManager.php

<?php
// ============================================
// FILE: includes/AvailabilityManager.php
// Description: Complete availability management class
// ============================================

class AvailabilityManager {
    private $conn;
    private $debug_log;
    
    public function __construct($conn) {
        $this->conn = $conn;
        $this->debug_log = __DIR__ . '/../logs/availability_debug.log';
    }
    
    /**
     * Log debug message
     */
    private function log($message, $data = null) {
        $log_entry = date('Y-m-d H:i:s') . " - " . $message;
        if ($data !== null) {
            $log_entry .= " - " . print_r($data, true);
        }
        file_put_contents($this->debug_log, $log_entry . PHP_EOL, FILE_APPEND);
    }
    
    /**
     * Check if listing is available for given dates
     */
    public function isAvailable($listing_id, $check_in, $check_out, $exclude_reservation_id = null) {
        $check_in = date('Y-m-d', strtotime($check_in));
        $check_out = date('Y-m-d', strtotime($check_out));
        
        // First check listing availability status
        $listing_check = $this->conn->query("
            SELECT availability_status, status, approval_status 
            FROM listings 
            WHERE id = $listing_id
        ")->fetch_assoc();
        
        if (!$listing_check) {
            $this->log("Listing not found: $listing_id");
            return false;
        }
        
        // Check if listing is available
        if ($listing_check['availability_status'] !== 'available') {
            $this->log("Listing $listing_id not available (status: {$listing_check['availability_status']})");
            return false;
        }
        
        if ($listing_check['status'] !== 'active' || $listing_check['approval_status'] !== 'approved') {
            $this->log("Listing $listing_id not active");
            return false;
        }
        
        // Check for overlapping reservations
        $sql = "
            SELECT id, check_in_date, check_out_date 
            FROM reservation_records 
            WHERE listing_id = $listing_id 
            AND status IN ('reserved', 'active')
        ";
        if ($exclude_reservation_id) {
            $sql .= " AND id != $exclude_reservation_id";
        }
        
        $reservations = $this->conn->query($sql);
        
        while ($res = $reservations->fetch_assoc()) {
            $existing_in = $res['check_in_date'];
            $existing_out = $res['check_out_date'];
            
            // Check for date overlap
            if ($check_in < $existing_out && $check_out > $existing_in) {
                $this->log("Date overlap detected: requested [$check_in - $check_out] conflicts with existing reservation {$res['id']} [$existing_in - $existing_out]");
                return false;
            }
        }
        
        // Also check availability calendar if populated
        $calendar_check = $this->conn->query("
            SELECT booking_date FROM availability_calendar 
            WHERE listing_id = $listing_id 
            AND booking_date BETWEEN '$check_in' AND DATE_SUB('$check_out', INTERVAL 1 DAY)
            AND is_available = 0
        ");
        
        if ($calendar_check->num_rows > 0) {
            $this->log("Calendar shows unavailable dates for listing $listing_id");
            return false;
        }
        
        $this->log("Listing $listing_id is available for dates $check_in to $check_out");
        return true;
    }
    
    /**
     * Create a reservation after successful deposit payment
     */
    public function createReservation($transaction_id, $payment_data = []) {
        $this->log("Creating reservation for transaction: $transaction_id");
        
        // Start transaction to ensure consistency
        $this->conn->begin_transaction();
        
        try {
            // Get transaction details with listing and booking info
            $txn = $this->conn->query("
                SELECT t.*, 
                       l.id as listing_id, l.title, l.type, l.seller_id,
                       rb.check_in_date, rb.check_out_date, rb.total_nights,
                       rb.id as booking_id
                FROM transactions t
                JOIN listings l ON t.listing_id = l.id
                LEFT JOIN rental_bookings rb ON rb.transaction_id = t.id
                WHERE t.id = $transaction_id
            ")->fetch_assoc();
            
            if (!$txn) {
                throw new Exception("Transaction not found: $transaction_id");
            }
            
            // Only create reservation for rental listings
            if ($txn['type'] !== 'rental') {
                $this->log("Not a rental listing, skipping reservation");
                $this->conn->commit();
                return ['success' => true, 'message' => 'Not a rental item'];
            }
            
            // Check if dates are provided
            $check_in = $txn['check_in_date'];
            $check_out = $txn['check_out_date'];
            
            if (empty($check_in) || empty($check_out)) {
                throw new Exception("Missing check-in or check-out dates");
            }
            
            // Verify availability again (prevent double-booking)
            if (!$this->isAvailable($txn['listing_id'], $check_in, $check_out)) {
                throw new Exception("Listing is no longer available for selected dates");
            }
            
            // Calculate deposit amount (30% of total)
            $deposit_percent = 30;
            $deposit_amount = $txn['total_amount'] * ($deposit_percent / 100);
            
            // Create reservation record
            $stmt = $this->conn->prepare("
                INSERT INTO reservation_records 
                (listing_id, transaction_id, buyer_id, seller_id, check_in_date, check_out_date, 
                 total_nights, total_amount, deposit_amount, status, payment_reference, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'reserved', ?, NOW())
            ");
            
            $payment_ref = $payment_data['payment_code'] ?? $payment_data['reference'] ?? 'DEPOSIT_' . $transaction_id;
            
            $stmt->bind_param(
                "iiiisssdss", 
                $txn['listing_id'],
                $transaction_id,
                $txn['buyer_id'],
                $txn['seller_id'],
                $check_in,
                $check_out,
                $txn['total_nights'],
                $txn['total_amount'],
                $deposit_amount,
                $payment_ref
            );
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to create reservation: " . $this->conn->error);
            }
            
            $reservation_id = $this->conn->insert_id;
            $this->log("Reservation created: ID $reservation_id");
            
            // Update listing availability status to 'reserved'
            $this->conn->query("
                UPDATE listings 
                SET availability_status = 'reserved', updated_at = NOW()
                WHERE id = {$txn['listing_id']}
            ");
            
            // Update rental_booking if exists
            if ($txn['booking_id']) {
                $this->conn->query("
                    UPDATE rental_bookings 
                    SET reservation_id = $reservation_id, status = 'confirmed'
                    WHERE id = {$txn['booking_id']}
                ");
            }
            
            // Populate availability calendar for these dates
            $this->populateCalendar($txn['listing_id'], $check_in, $check_out, $reservation_id);
            
            // Add to status history
            $this->addStatusHistory($reservation_id, null, 'reserved', $txn['buyer_id'], 'buyer', 'Deposit payment confirmed');
            
            // Update transaction with reservation_id
            $this->conn->query("
                UPDATE transactions 
                SET reservation_id = $reservation_id, updated_at = NOW()
                WHERE id = $transaction_id
            ");
            
            $this->conn->commit();
            $this->log("Reservation completed successfully");
            
            return [
                'success' => true,
                'reservation_id' => $reservation_id,
                'message' => 'Listing reserved successfully'
            ];
            
        } catch (Exception $e) {
            $this->conn->rollback();
            $this->log("ERROR creating reservation: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Populate availability calendar for reserved dates
     */
    private function populateCalendar($listing_id, $check_in, $check_out, $reservation_id) {
        $current = strtotime($check_in);
        $end = strtotime($check_out);
        
        while ($current < $end) {
            $date = date('Y-m-d', $current);
            
            $this->conn->query("
                INSERT INTO availability_calendar (listing_id, booking_date, is_available, reservation_id, updated_at)
                VALUES ($listing_id, '$date', 0, $reservation_id, NOW())
                ON DUPLICATE KEY UPDATE 
                    is_available = 0, 
                    reservation_id = $reservation_id,
                    updated_at = NOW()
            ");
            
            $current = strtotime('+1 day', $current);
        }
        
        $this->log("Calendar populated for listing $listing_id from $check_in to $check_out");
    }
    
    /**
     * Release a reservation (when cancelled/refunded)
     */
    public function releaseReservation($reservation_id, $reason = '', $cancelled_by = null, $cancelled_by_type = 'system') {
        $this->log("Releasing reservation: $reservation_id, Reason: $reason");
        
        $this->conn->begin_transaction();
        
        try {
            // Get reservation details
            $reservation = $this->conn->query("
                SELECT * FROM reservation_records WHERE id = $reservation_id
            ")->fetch_assoc();
            
            if (!$reservation) {
                throw new Exception("Reservation not found: $reservation_id");
            }
            
            // Only release if not already completed or cancelled
            if (in_array($reservation['status'], ['completed', 'cancelled', 'refunded'])) {
                $this->log("Reservation already in terminal state: {$reservation['status']}");
                $this->conn->commit();
                return ['success' => true, 'message' => 'Already released'];
            }
            
            // Update reservation status
            $old_status = $reservation['status'];
            $new_status = 'cancelled';
            
            $this->conn->query("
                UPDATE reservation_records 
                SET status = 'cancelled', 
                    cancelled_at = NOW(), 
                    cancellation_reason = '$reason',
                    updated_at = NOW()
                WHERE id = $reservation_id
            ");
            
            // Update listing availability back to 'available'
            $this->conn->query("
                UPDATE listings 
                SET availability_status = 'available', updated_at = NOW()
                WHERE id = {$reservation['listing_id']}
            ");
            
            // Update availability calendar for these dates (make them available again)
            $this->conn->query("
                UPDATE availability_calendar 
                SET is_available = 1, 
                    reservation_id = NULL,
                    updated_at = NOW()
                WHERE listing_id = {$reservation['listing_id']}
                AND booking_date BETWEEN '{$reservation['check_in_date']}' AND DATE_SUB('{$reservation['check_out_date']}', INTERVAL 1 DAY)
            ");
            
            // Add to status history
            $changed_by = $cancelled_by ?? $reservation['buyer_id'];
            $changed_by_type = $cancelled_by_type;
            
            $this->addStatusHistory($reservation_id, $old_status, $new_status, $changed_by, $changed_by_type, $reason);
            
            $this->conn->commit();
            $this->log("Reservation $reservation_id released successfully");
            
            return ['success' => true, 'message' => 'Reservation released'];
            
        } catch (Exception $e) {
            $this->conn->rollback();
            $this->log("ERROR releasing reservation: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Complete a reservation (after checkout)
     */
    public function completeReservation($reservation_id, $completed_by = null) {
        $this->log("Completing reservation: $reservation_id");
        
        $this->conn->begin_transaction();
        
        try {
            $reservation = $this->conn->query("
                SELECT * FROM reservation_records WHERE id = $reservation_id
            ")->fetch_assoc();
            
            if (!$reservation) {
                throw new Exception("Reservation not found: $reservation_id");
            }
            
            if ($reservation['status'] === 'completed') {
                $this->log("Reservation already completed");
                $this->conn->commit();
                return ['success' => true, 'message' => 'Already completed'];
            }
            
            $old_status = $reservation['status'];
            
            // Update reservation to completed
            $this->conn->query("
                UPDATE reservation_records 
                SET status = 'completed', updated_at = NOW()
                WHERE id = $reservation_id
            ");
            
            // Update listing availability back to 'available'
            $this->conn->query("
                UPDATE listings 
                SET availability_status = 'available', updated_at = NOW()
                WHERE id = {$reservation['listing_id']}
            ");
            
            // Add to status history
            $changed_by = $completed_by ?? $reservation['buyer_id'];
            $this->addStatusHistory($reservation_id, $old_status, 'completed', $changed_by, 'buyer', 'Reservation completed');
            
            $this->conn->commit();
            $this->log("Reservation $reservation_id completed");
            
            return ['success' => true, 'message' => 'Reservation completed'];
            
        } catch (Exception $e) {
            $this->conn->rollback();
            $this->log("ERROR completing reservation: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Add entry to reservation status history
     */
    private function addStatusHistory($reservation_id, $old_status, $new_status, $changed_by, $changed_by_type, $reason = null) {
        $reason = $reason ? $this->conn->real_escape_string($reason) : null;
        $old_status = $old_status ?: 'NULL';
        
        $this->conn->query("
            INSERT INTO reservation_status_history 
            (reservation_id, old_status, new_status, changed_by, changed_by_type, reason, created_at)
            VALUES ($reservation_id, " . ($old_status === 'NULL' ? 'NULL' : "'$old_status'") . ", '$new_status', $changed_by, '$changed_by_type', " . ($reason ? "'$reason'" : "NULL") . ", NOW())
        ");
    }
    
    /**
     * Get available listings for browsing (excludes reserved ones)
     */
    public function getAvailableListings($type = null, $limit = null, $offset = null, $search_params = []) {
        $sql = "
            SELECT l.*, u.full_name as seller_name
            FROM listings l
            JOIN users u ON l.seller_id = u.id
            WHERE l.status = 'active' 
            AND l.approval_status = 'approved'
            AND l.availability_status = 'available'
        ";
        
        if ($type) {
            $sql .= " AND l.type = '" . $this->conn->real_escape_string($type) . "'";
        }
        
        // Add search filters
        if (!empty($search_params['search'])) {
            $search = $this->conn->real_escape_string($search_params['search']);
            $sql .= " AND (l.title LIKE '%$search%' OR l.description LIKE '%$search%' OR l.location LIKE '%$search%')";
        }
        
        if (!empty($search_params['min_price'])) {
            $sql .= " AND l.price >= " . floatval($search_params['min_price']);
        }
        
        if (!empty($search_params['max_price'])) {
            $sql .= " AND l.price <= " . floatval($search_params['max_price']);
        }
        
        if (!empty($search_params['location'])) {
            $location = $this->conn->real_escape_string($search_params['location']);
            $sql .= " AND l.location LIKE '%$location%'";
        }
        
        $sql .= " ORDER BY l.created_at DESC";
        
        if ($limit) {
            $sql .= " LIMIT $limit";
            if ($offset) {
                $sql .= " OFFSET $offset";
            }
        }
        
        return $this->conn->query($sql);
    }
    
    /**
     * Get count of available listings
     */
    public function getAvailableListingsCount($type = null, $search_params = []) {
        $sql = "
            SELECT COUNT(*) as total
            FROM listings l
            WHERE l.status = 'active' 
            AND l.approval_status = 'approved'
            AND l.availability_status = 'available'
        ";
        
        if ($type) {
            $sql .= " AND l.type = '" . $this->conn->real_escape_string($type) . "'";
        }
        
        if (!empty($search_params['search'])) {
            $search = $this->conn->real_escape_string($search_params['search']);
            $sql .= " AND (l.title LIKE '%$search%' OR l.description LIKE '%$search%' OR l.location LIKE '%$search%')";
        }
        
        if (!empty($search_params['min_price'])) {
            $sql .= " AND l.price >= " . floatval($search_params['min_price']);
        }
        
        if (!empty($search_params['max_price'])) {
            $sql .= " AND l.price <= " . floatval($search_params['max_price']);
        }
        
        if (!empty($search_params['location'])) {
            $location = $this->conn->real_escape_string($search_params['location']);
            $sql .= " AND l.location LIKE '%$location%'";
        }
        
        $result = $this->conn->query($sql);
        return $result->fetch_assoc()['total'];
    }
    
    /**
     * Check if user has any active reservations for a listing
     */
    public function hasActiveReservation($listing_id, $user_id) {
        $result = $this->conn->query("
            SELECT id FROM reservation_records 
            WHERE listing_id = $listing_id 
            AND buyer_id = $user_id 
            AND status IN ('reserved', 'active')
            AND check_out_date > CURDATE()
            LIMIT 1
        ");
        return $result->num_rows > 0;
    }
    
    /**
     * Get upcoming reservations for a seller
     */
    public function getSellerUpcomingReservations($seller_id, $limit = 10) {
        return $this->conn->query("
            SELECT r.*, l.title, l.location, u.full_name as buyer_name, u.email as buyer_email
            FROM reservation_records r
            JOIN listings l ON r.listing_id = l.id
            JOIN users u ON r.buyer_id = u.id
            WHERE r.seller_id = $seller_id
            AND r.status IN ('reserved', 'active')
            AND r.check_in_date >= CURDATE()
            ORDER BY r.check_in_date ASC
            LIMIT $limit
        ");
    }
    
    /**
     * Process auto-release of expired reservations (cron job)
     */
    public function processExpiredReservations() {
        $expired = $this->conn->query("
            SELECT id FROM reservation_records 
            WHERE status IN ('reserved', 'active')
            AND check_out_date < CURDATE()
        ");
        
        $released = 0;
        while ($res = $expired->fetch_assoc()) {
            $result = $this->completeReservation($res['id']);
            if ($result['success']) {
                $released++;
            }
        }
        
        $this->log("Processed $released expired reservations");
        return $released;
    }
}
?>

BRS/includes/Sanitizer.php

<?php
// includes/Sanitizer.php - Reusable sanitization trait

trait Sanitizer {
    
    /**
     * Sanitize all inputs
     */
    public function sanitizeInput($data) {
        if (is_array($data)) {
            return array_map([$this, 'sanitizeInput'], $data);
        }
        return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
    }
    
    /**
     * Get sanitized POST data
     */
    public function getPost($key, $default = null) {
        if (!isset($_POST[$key])) {
            return $default;
        }
        return $this->sanitizeInput($_POST[$key]);
    }
    
    /**
     * Get sanitized GET data
     */
    public function getQuery($key, $default = null) {
        if (!isset($_GET[$key])) {
            return $default;
        }
        return $this->sanitizeInput($_GET[$key]);
    }
    
    /**
     * Get sanitized integer from POST
     */
    public function getPostInt($key, $default = 0) {
        return sanitizeInt($this->getPost($key, $default));
    }
    
    /**
     * Get sanitized float from POST
     */
    public function getPostFloat($key, $default = 0.00) {
        return sanitizeFloat($this->getPost($key, $default));
    }
}

BRS/includes/admin_functions.php

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
    $sql = "SELECT d.*, t.total_amount, u.full_name as raised_by_name 
            FROM disputes d
            JOIN transactions t ON d.transaction_id = t.id
            JOIN users u ON d.raised_by = u.id
            ORDER BY d.created_at DESC 
            LIMIT $limit";
    return $conn->query($sql);
}

BRS/includes/auth.php

<?php
// includes/auth.php - Updated with company support

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';

function isLoggedIn() {
    return isset($_SESSION['user_logged_in']) && $_SESSION['user_logged_in'] === true;
}

function requireLogin() {
    if (!isLoggedIn()) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
        header('Location: /broker_system/auth/login.php');
        exit;
    }
}

function getCurrentUser() {
    if (!isLoggedIn()) return null;
    return [
        'id' => $_SESSION['user_id'],
        'name' => $_SESSION['user_name'],
        'email' => $_SESSION['user_email'],
        'role' => $_SESSION['user_role'],
        'balance' => $_SESSION['user_balance'] ?? 0
    ];
}

function userLogin($userId, $fullName, $email, $role, $balance) {
    $_SESSION['user_logged_in'] = true;
    $_SESSION['user_id'] = $userId;
    $_SESSION['user_name'] = $fullName;
    $_SESSION['user_email'] = $email;
    $_SESSION['user_role'] = $role;
    $_SESSION['user_balance'] = $balance;
    
    // Update last login
    $conn = getDbConnection();
    $stmt = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $conn->close();
}

function userLogout() {
    $_SESSION = array();
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    header('Location: /broker_system/auth/login.php');
    exit;
}

function isAdminLoggedIn() {
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

function requireAdminLogin() {
    if (!isAdminLoggedIn()) {
        header('Location: /broker_system/auth/login.php');
        exit;
    }
}

function adminLogout() {
    session_destroy();
    header('Location: /broker_system/auth/login.php');
    exit;
}
?>

BRS/includes/chat_functions.php

<?php
// includes/chat_functions.php - Complete Fixed Version

require_once __DIR__ . '/../config/database.php';

function getOrCreateConversation($conn, $user_id, $broker_id) {
    // Prevent creating conversation with self
    if ($user_id == $broker_id) {
        return false;
    }
    
    // Check if conversation exists
    $stmt = $conn->prepare("SELECT id FROM conversations WHERE (user_id = ? AND broker_id = ?) OR (user_id = ? AND broker_id = ?)");
    $stmt->bind_param("iiii", $user_id, $broker_id, $broker_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        return $result->fetch_assoc()['id'];
    }
    
    // Create new conversation
    $user_role = getUserRole($conn, $user_id);
    $broker_role = getUserRole($conn, $broker_id);
    
    // Ensure one is user and one is broker/admin
    $actual_user_id = ($user_role == 'user') ? $user_id : $broker_id;
    $actual_broker_id = ($broker_role == 'admin' || $broker_role == 'broker') ? $broker_id : $user_id;
    
    // Don't create if both are same
    if ($actual_user_id == $actual_broker_id) {
        return false;
    }
    
    $stmt = $conn->prepare("INSERT INTO conversations (user_id, broker_id, created_at, updated_at) VALUES (?, ?, NOW(), NOW())");
    $stmt->bind_param("ii", $actual_user_id, $actual_broker_id);
    $stmt->execute();
    
    return $conn->insert_id;
}

function getUserRole($conn, $user_id) {
    $stmt = $conn->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        return $result->fetch_assoc()['role'];
    }
    return 'user';
}

function sendMessage($conn, $conversation_id, $sender_id, $receiver_id, $message) {
    // Don't send if sender and receiver are same
    if ($sender_id == $receiver_id) {
        return false;
    }
    
    $message = trim($message);
    if (empty($message)) return false;
    
    $stmt = $conn->prepare("INSERT INTO messages (conversation_id, sender_id, receiver_id, message, status, created_at) VALUES (?, ?, ?, ?, 'sent', NOW())");
    $stmt->bind_param("iiis", $conversation_id, $sender_id, $receiver_id, $message);
    if (!$stmt->execute()) {
        return false;
    }
    $message_id = $conn->insert_id;
    
    // Update conversation last message
    $stmt2 = $conn->prepare("UPDATE conversations SET last_message = ?, last_message_time = NOW(), updated_at = NOW() WHERE id = ?");
    $stmt2->bind_param("si", $message, $conversation_id);
    $stmt2->execute();
    
    // Update unread count for receiver
    $receiver_role = getUserRole($conn, $receiver_id);
    if ($receiver_role == 'admin' || $receiver_role == 'broker') {
        $conn->query("UPDATE conversations SET broker_unread_count = broker_unread_count + 1 WHERE id = $conversation_id");
    } else {
        $conn->query("UPDATE conversations SET user_unread_count = user_unread_count + 1 WHERE id = $conversation_id");
    }
    
    return $message_id;
}

function getMessagesWithDeleteFilter($conn, $conversation_id, $user_id, $limit = 50, $offset = 0) {
    $stmt = $conn->prepare("
        SELECT m.*, u.full_name as sender_name, u.role as sender_role
        FROM messages m
        JOIN users u ON m.sender_id = u.id
        WHERE m.conversation_id = ? 
        AND NOT (m.deleted_by_sender = 1 AND m.sender_id = ?)
        AND NOT (m.deleted_by_receiver = 1 AND m.receiver_id = ?)
        ORDER BY m.created_at ASC
        LIMIT ? OFFSET ?
    ");
    $stmt->bind_param("iiiii", $conversation_id, $user_id, $user_id, $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $messages = [];
    while ($row = $result->fetch_assoc()) {
        // Get reactions for this message
        $reactions = getMessageReactions($conn, $row['id']);
        
        // Get user's own reaction
        $my_reaction = null;
        $reaction_check = $conn->prepare("SELECT reaction_type FROM message_reactions WHERE message_id = ? AND user_id = ?");
        $reaction_check->bind_param("ii", $row['id'], $user_id);
        $reaction_check->execute();
        $reaction_result = $reaction_check->get_result();
        if ($reaction_result->num_rows > 0) {
            $my_reaction = $reaction_result->fetch_assoc()['reaction_type'];
        }
        
        $row['reactions'] = $reactions;
        $row['my_reaction'] = $my_reaction;
        $messages[] = $row;
    }
    
    return $messages;
}

function getMessageReactions($conn, $message_id) {
    $reactions = $conn->prepare("
        SELECT reaction_type, COUNT(*) as count
        FROM message_reactions 
        WHERE message_id = ? 
        GROUP BY reaction_type
    ");
    $reactions->bind_param("i", $message_id);
    $reactions->execute();
    $result = $reactions->get_result();
    
    $reaction_data = [];
    while($row = $result->fetch_assoc()) {
        $reaction_data[$row['reaction_type']] = $row['count'];
    }
    return $reaction_data;
}

function addReaction($conn, $message_id, $user_id, $reaction_type) {
    // First, get the message to verify user has access
    $msg_check = $conn->prepare("
        SELECT m.*, c.user_id, c.broker_id 
        FROM messages m 
        JOIN conversations c ON m.conversation_id = c.id 
        WHERE m.id = ?
    ");
    $msg_check->bind_param("i", $message_id);
    $msg_check->execute();
    $message = $msg_check->get_result()->fetch_assoc();
    
    if (!$message || ($message['user_id'] != $user_id && $message['broker_id'] != $user_id)) {
        return false;
    }
    
    // Check if reaction exists
    $check = $conn->prepare("SELECT id FROM message_reactions WHERE message_id = ? AND user_id = ?");
    $check->bind_param("ii", $message_id, $user_id);
    $check->execute();
    $result = $check->get_result();
    
    if ($result->num_rows > 0) {
        // Update existing reaction
        $update = $conn->prepare("UPDATE message_reactions SET reaction_type = ? WHERE message_id = ? AND user_id = ?");
        $update->bind_param("sii", $reaction_type, $message_id, $user_id);
        $update->execute();
    } else {
        // Add new reaction
        $insert = $conn->prepare("INSERT INTO message_reactions (message_id, user_id, reaction_type) VALUES (?, ?, ?)");
        $insert->bind_param("iis", $message_id, $user_id, $reaction_type);
        $insert->execute();
    }
    
    return true;
}

function deleteMessage($conn, $message_id, $user_id) {
    // Get message details
    $msg = $conn->prepare("
        SELECT m.*, c.user_id, c.broker_id 
        FROM messages m 
        JOIN conversations c ON m.conversation_id = c.id 
        WHERE m.id = ?
    ");
    $msg->bind_param("i", $message_id);
    $msg->execute();
    $message = $msg->get_result()->fetch_assoc();
    
    if (!$message) {
        return ['success' => false, 'error' => 'Message not found'];
    }
    
    // Determine if user is sender or receiver
    $is_sender = ($message['sender_id'] == $user_id);
    $is_receiver = ($message['receiver_id'] == $user_id);
    
    if (!$is_sender && !$is_receiver) {
        return ['success' => false, 'error' => 'Unauthorized'];
    }
    
    if ($is_sender) {
        // Delete for sender only
        $conn->query("UPDATE messages SET deleted_by_sender = 1, deleted_at = NOW() WHERE id = $message_id");
    } else {
        // Delete for receiver only
        $conn->query("UPDATE messages SET deleted_by_receiver = 1, deleted_at = NOW() WHERE id = $message_id");
    }
    
    // Check if both have deleted, then hard delete
    $check = $conn->query("SELECT deleted_by_sender, deleted_by_receiver FROM messages WHERE id = $message_id")->fetch_assoc();
    if ($check['deleted_by_sender'] && $check['deleted_by_receiver']) {
        $conn->query("DELETE FROM messages WHERE id = $message_id");
        $conn->query("DELETE FROM message_reactions WHERE message_id = $message_id");
    }
    
    return ['success' => true];
}

function markMessagesAsRead($conn, $conversation_id, $user_id) {
    $user_role = getUserRole($conn, $user_id);
    
    if ($user_role == 'admin' || $user_role == 'broker') {
        $conn->query("UPDATE conversations SET broker_unread_count = 0 WHERE id = $conversation_id");
    } else {
        $conn->query("UPDATE conversations SET user_unread_count = 0 WHERE id = $conversation_id");
    }
    
    $conn->query("UPDATE messages SET is_read = 1, read_at = NOW() WHERE conversation_id = $conversation_id AND receiver_id = $user_id AND is_read = 0");
}

function getUserConversations($conn, $user_id) {
    $user_role = getUserRole($conn, $user_id);
    
    if ($user_role == 'admin' || $user_role == 'broker') {
        $sql = "
            SELECT c.*, 
                   u.id as other_user_id, u.full_name as other_user_name, u.email as other_user_email,
                   CASE WHEN c.broker_unread_count > 0 THEN c.broker_unread_count ELSE 0 END as unread_count
            FROM conversations c
            JOIN users u ON c.user_id = u.id
            WHERE c.broker_id = ? AND c.status = 'active'
              AND u.role = 'user'
              AND u.id != ?
            ORDER BY c.updated_at DESC
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $user_id, $user_id);
    } else {
        $sql = "
            SELECT c.*, 
                   u.id as other_user_id, u.full_name as other_user_name, u.email as other_user_email,
                   CASE WHEN c.user_unread_count > 0 THEN c.user_unread_count ELSE 0 END as unread_count
            FROM conversations c
            JOIN users u ON c.broker_id = u.id
            WHERE c.user_id = ? AND c.status = 'active'
              AND u.role IN ('admin', 'broker')
              AND u.id != ?
            ORDER BY c.updated_at DESC
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $user_id, $user_id);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result;
}

function getUnreadMessageCount($conn, $user_id) {
    $user_role = getUserRole($conn, $user_id);
    
    if ($user_role == 'admin' || $user_role == 'broker') {
        $result = $conn->query("SELECT SUM(broker_unread_count) as total FROM conversations WHERE broker_id = $user_id");
    } else {
        $result = $conn->query("SELECT SUM(user_unread_count) as total FROM conversations WHERE user_id = $user_id");
    }
    
    $row = $result->fetch_assoc();
    return $row['total'] ?? 0;
}

function getConversationById($conn, $conversation_id, $user_id) {
    $user_role = getUserRole($conn, $user_id);
    
    if ($user_role == 'admin' || $user_role == 'broker') {
        $stmt = $conn->prepare("
            SELECT c.*, 
                   u.id as other_user_id, u.full_name as other_user_name, u.email as other_user_email,
                   CASE WHEN c.broker_unread_count > 0 THEN c.broker_unread_count ELSE 0 END as unread_count
            FROM conversations c
            JOIN users u ON c.user_id = u.id
            WHERE c.id = ? AND c.broker_id = ? AND c.status = 'active'
              AND u.role = 'user'
        ");
        $stmt->bind_param("ii", $conversation_id, $user_id);
    } else {
        $stmt = $conn->prepare("
            SELECT c.*, 
                   u.id as other_user_id, u.full_name as other_user_name, u.email as other_user_email,
                   CASE WHEN c.user_unread_count > 0 THEN c.user_unread_count ELSE 0 END as unread_count
            FROM conversations c
            JOIN users u ON c.broker_id = u.id
            WHERE c.id = ? AND c.user_id = ? AND c.status = 'active'
              AND u.role IN ('admin', 'broker')
        ");
        $stmt->bind_param("ii", $conversation_id, $user_id);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $conversation = $result->fetch_assoc();
        // Don't return conversation if it's with self
        if ($conversation['other_user_id'] == $user_id) {
            return null;
        }
        return $conversation;
    }
    return null;
}
?>

BRS/includes/escrow_functions.php

<?php
// ============================================
// FILE: includes/escrow_functions.php
// ============================================
// Complete Escrow Transaction Engine

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';

/**
 * Add entry to transaction timeline
 */
function addTransactionTimeline($conn, $transaction_id, $status, $description, $performed_by = null) {
    $stmt = $conn->prepare("
        INSERT INTO transaction_timeline (transaction_id, status, action, description, performed_by, created_at) 
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $action = str_replace('_', ' ', $status);
    $stmt->bind_param("isssi", $transaction_id, $status, $action, $description, $performed_by);
    return $stmt->execute();
}

/**
 * Initialize escrow for a transaction
 */
function initializeEscrow($conn, $transaction_id, $buyer_id, $seller_id, $amount, $type = 'full_payment') {
    // Insert into escrow_accounts
    $stmt = $conn->prepare("
        INSERT INTO escrow_accounts (transaction_id, user_id, amount, type, status, created_at) 
        VALUES (?, ?, ?, ?, 'held', NOW())
    ");
    $stmt->bind_param("iids", $transaction_id, $buyer_id, $amount, $type);
    $stmt->execute();
    
    // Update transaction escrow status
    $conn->query("
        UPDATE transactions 
        SET escrow_held = escrow_held + $amount,
            escrow_status = 'active',
            updated_at = NOW()
        WHERE id = $transaction_id
    ");
    
    // Add to timeline
    addTransactionTimeline($conn, $transaction_id, 'escrow_activated', 
        "Escrow activated with " . formatMoney($amount), $buyer_id);
    
    // Schedule auto-release based on listing type
    $listing = $conn->query("
        SELECT l.type FROM transactions t 
        JOIN listings l ON t.listing_id = l.id 
        WHERE t.id = $transaction_id
    ")->fetch_assoc();
    
    $auto_days = 7; // default
    if ($listing['type'] == 'rental') $auto_days = 14;
    if ($listing['type'] == 'product') $auto_days = 5;
    if ($listing['type'] == 'job') $auto_days = 10;
    
    $release_date = date('Y-m-d H:i:s', strtotime("+$auto_days days"));
    $conn->query("
        INSERT INTO escrow_release_queue (transaction_id, scheduled_release_date, status) 
        VALUES ($transaction_id, '$release_date', 'pending')
    ");
    
    // Update transaction with release date
    $conn->query("
        UPDATE transactions 
        SET auto_release_days = $auto_days, escrow_release_date = '$release_date'
        WHERE id = $transaction_id
    ");
    
    return true;
}

/**
 * Process payment from buyer (with Telebirr code)
 */
function processBuyerPayment($conn, $transaction_id, $buyer_id, $amount, $payment_code) {
    // Record payment
    $stmt = $conn->prepare("
        INSERT INTO payments (transaction_id, user_id, amount, type, telebirr_code_5digit, status, confirmed_at, created_at) 
        VALUES (?, ?, ?, 'deposit_buyer', ?, 'confirmed', NOW(), NOW())
    ");
    $stmt->bind_param("iids", $transaction_id, $buyer_id, $amount, $payment_code);
    $stmt->execute();
    
    // Initialize escrow
    $transaction = $conn->query("SELECT seller_id FROM transactions WHERE id = $transaction_id")->fetch_assoc();
    initializeEscrow($conn, $transaction_id, $buyer_id, $transaction['seller_id'], $amount);
    
    return true;
}

/**
 * Mark delivery by seller
 */
function markDelivery($conn, $transaction_id, $seller_id, $delivery_notes = '') {
    require_once __DIR__ . '/transaction_workflow.php';
    return markSellerConfirmed($conn, $transaction_id, $seller_id, $delivery_notes);
}

/**
 * Confirm receipt by buyer and release payment
 */
function confirmReceiptAndRelease($conn, $transaction_id, $buyer_id, $notes = '') {
    require_once __DIR__ . '/transaction_workflow.php';
    return markBuyerConfirmed($conn, $transaction_id, $buyer_id, $notes);
}

/**
 * Release payment from escrow
 */
function releaseEscrowPayment($conn, $transaction_id, $released_by, $released_by_type, $notes = '') {
    $transaction = $conn->query("
        SELECT t.*, l.title, l.seller_id
        FROM transactions t
        JOIN listings l ON t.listing_id = l.id
        WHERE t.id = $transaction_id
    ")->fetch_assoc();
    
    if (!$transaction) {
        return ['success' => false, 'error' => 'Transaction not found'];
    }

    if (($transaction['status'] ?? '') === 'disputed') {
        return ['success' => false, 'error' => 'Cannot release funds while disputed'];
    }

    if ($released_by_type !== 'admin' && $released_by_type !== 'system' && $released_by_type !== 'dual_confirm') {
        $seller_ok = (int) ($transaction['seller_confirmed'] ?? 0) === 1
            || ($transaction['delivery_status'] ?? '') === 'delivered';
        $buyer_ok = (int) ($transaction['buyer_confirmed'] ?? 0) === 1;
        if (!$seller_ok || !$buyer_ok) {
            return ['success' => false, 'error' => 'Both seller and buyer must confirm before release'];
        }
    }
    
    $release_amount = $transaction['total_amount'] - $transaction['commission_amount'];
    
    $conn->begin_transaction();
    
    try {
        // Update escrow accounts
        $conn->query("
            UPDATE escrow_accounts 
            SET status = 'released', released_at = NOW()
            WHERE transaction_id = $transaction_id AND status = 'held'
        ");
        
        // Update user balance (seller gets paid)
        $conn->query("
            UPDATE users 
            SET balance = balance + $release_amount 
            WHERE id = {$transaction['seller_id']}
        ");
        
        // Update transaction
        $conn->query("
            UPDATE transactions 
            SET status = 'completed',
                escrow_status = 'released',
                payment_released_at = NOW(),
                escrow_release_method = '$released_by_type',
                confirmed_at = NOW(),
                completed_at = NOW(),
                updated_at = NOW()
            WHERE id = $transaction_id
        ");
        
        // Cancel auto-release queue
        $conn->query("
            UPDATE escrow_release_queue 
            SET status = 'cancelled' 
            WHERE transaction_id = $transaction_id AND status = 'pending'
        ");
        
        // Add release history
        $stmt = $conn->prepare("
            INSERT INTO escrow_release_history (transaction_id, released_by, released_by_type, amount, notes, created_at) 
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param("iisds", $transaction_id, $released_by, $released_by_type, $release_amount, $notes);
        $stmt->execute();
        
        // Add wallet transaction for seller
        $conn->query("
            INSERT INTO wallet_transactions (user_id, amount, type, description, created_at) 
            VALUES ({$transaction['seller_id']}, $release_amount, 'deposit', 
                   'Payment released for: {$transaction['title']}', NOW())
        ");
        
        // Add timeline entry
        addTransactionTimeline($conn, $transaction_id, 'payment_released', 
            "Payment of " . formatMoney($release_amount) . " released to seller", $released_by);
        
        // Create notification for seller
        $notif_stmt = $conn->prepare("
            INSERT INTO notifications (user_id, title, message, created_at) 
            VALUES (?, 'Payment Released', 'Payment of " . formatMoney($release_amount) . " has been released to your wallet for {$transaction['title']}', NOW())
        ");
        $notif_stmt->bind_param("i", $transaction['seller_id']);
        $notif_stmt->execute();
        
        $conn->commit();
        
        return ['success' => true, 'amount' => $release_amount];
        
    } catch (Exception $e) {
        $conn->rollback();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Process auto-release for expired escrow
 */
function processAutoReleaseQueue($conn) {
    $pending_releases = $conn->query("
        SELECT eq.*, t.total_amount, t.commission_amount, t.seller_id,
               t.seller_confirmed, t.buyer_confirmed, t.delivery_status
        FROM escrow_release_queue eq
        JOIN transactions t ON eq.transaction_id = t.id
        WHERE eq.status = 'pending' 
        AND eq.scheduled_release_date <= NOW()
        AND t.escrow_status = 'active'
        AND t.status NOT IN ('completed', 'disputed')
    ");
    
    $released_count = 0;
    while ($release = $pending_releases->fetch_assoc()) {
        $seller_ok = (int) ($release['seller_confirmed'] ?? 0) === 1
            || ($release['delivery_status'] ?? '') === 'delivered';
        $buyer_ok = (int) ($release['buyer_confirmed'] ?? 0) === 1;
        if (!$seller_ok || !$buyer_ok) {
            continue;
        }
        $result = releaseEscrowPayment($conn, $release['transaction_id'], 0, 'system',
            "Auto-release after escrow period expired and both parties confirmed");
        if ($result['success']) {
            $released_count++;
        }
    }
    
    return $released_count;
}

/**
 * Admin manual release
 */
function adminReleasePayment($conn, $transaction_id, $admin_id, $notes = '') {
    return releaseEscrowPayment($conn, $transaction_id, $admin_id, 'admin', $notes);
}

/**
 * Admin freeze transaction
 */
function adminFreezeTransaction($conn, $transaction_id, $admin_id, $reason = '') {
    $conn->query("
        UPDATE transactions 
        SET admin_frozen = 1, frozen_reason = '$reason', updated_at = NOW()
        WHERE id = $transaction_id
    ");
    
    addTransactionTimeline($conn, $transaction_id, 'frozen', 
        "Transaction frozen by admin. Reason: " . ($reason ?: 'Not specified'), $admin_id);
    
    // Cancel auto-release
    $conn->query("
        UPDATE escrow_release_queue 
        SET status = 'cancelled' 
        WHERE transaction_id = $transaction_id AND status = 'pending'
    ");
    
    return true;
}

/**
 * Admin unfreeze transaction
 */
function adminUnfreezeTransaction($conn, $transaction_id, $admin_id) {
    $conn->query("
        UPDATE transactions 
        SET admin_frozen = 0, frozen_reason = NULL, updated_at = NOW()
        WHERE id = $transaction_id
    ");
    
    addTransactionTimeline($conn, $transaction_id, 'unfrozen', 
        "Transaction unfrozen by admin", $admin_id);
    
    // Re-schedule auto-release
    $release_date = date('Y-m-d H:i:s', strtotime('+7 days'));
    $conn->query("
        INSERT INTO escrow_release_queue (transaction_id, scheduled_release_date, status) 
        VALUES ($transaction_id, '$release_date', 'pending')
        ON DUPLICATE KEY UPDATE scheduled_release_date = '$release_date', status = 'pending'
    ");
    
    return true;
}

/**
 * Get transaction status with escrow info
 */
function getTransactionEscrowStatus($conn, $transaction_id) {
    return $conn->query("
        SELECT t.*, 
               ea.amount as escrow_amount, ea.status as escrow_account_status,
               eq.scheduled_release_date,
               (SELECT COUNT(*) FROM transaction_timeline tt WHERE tt.transaction_id = t.id) as timeline_count
        FROM transactions t
        LEFT JOIN escrow_accounts ea ON t.id = ea.transaction_id
        LEFT JOIN escrow_release_queue eq ON t.id = eq.transaction_id AND eq.status = 'pending'
        WHERE t.id = $transaction_id
    ")->fetch_assoc();
}

/**
 * Get transaction timeline
 */
function getTransactionTimeline($conn, $transaction_id) {
    return $conn->query("
        SELECT * FROM transaction_timeline 
        WHERE transaction_id = $transaction_id 
        ORDER BY created_at ASC
    ");
}

/**
 * Calculate escrow summary for admin
 */
function getEscrowSummary($conn) {
    return [
        'total_held' => $conn->query("SELECT SUM(amount) as total FROM escrow_accounts WHERE status = 'held'")->fetch_assoc()['total'] ?? 0,
        'total_released' => $conn->query("SELECT SUM(amount) as total FROM escrow_accounts WHERE status = 'released'")->fetch_assoc()['total'] ?? 0,
        'active_transactions' => $conn->query("SELECT COUNT(*) as count FROM transactions WHERE escrow_status = 'active'")->fetch_assoc()['count'],
        'pending_release' => $conn->query("SELECT COUNT(*) as count FROM escrow_release_queue WHERE status = 'pending' AND scheduled_release_date <= NOW()")->fetch_assoc()['count']
    ];
}





// Add this function to your existing escrow_functions.php

/**
 * Refund payment to buyer (for disputes)
 */
function refundEscrowPayment($conn, $transaction_id, $admin_id, $notes = '') {
    $transaction = $conn->query("
        SELECT t.*, l.title, l.buyer_id, l.seller_id
        FROM transactions t
        JOIN listings l ON t.listing_id = l.id
        WHERE t.id = $transaction_id
    ")->fetch_assoc();
    
    if (!$transaction) {
        return ['success' => false, 'error' => 'Transaction not found'];
    }
    
    $refund_amount = $transaction['escrow_held'];
    
    $conn->begin_transaction();
    
    try {
        // Update escrow accounts
        $conn->query("
            UPDATE escrow_accounts 
            SET status = 'refunded', refunded_at = NOW()
            WHERE transaction_id = $transaction_id AND status = 'held'
        ");
        
        // Refund to buyer
        $conn->query("
            UPDATE users 
            SET balance = balance + $refund_amount 
            WHERE id = {$transaction['buyer_id']}
        ");
        
        // Update transaction
        $conn->query("
            UPDATE transactions 
            SET status = 'cancelled',
                escrow_status = 'refunded',
                updated_at = NOW()
            WHERE id = $transaction_id
        ");
        
        // Cancel auto-release
        $conn->query("
            UPDATE escrow_release_queue 
            SET status = 'cancelled' 
            WHERE transaction_id = $transaction_id AND status = 'pending'
        ");
        
        // Add refund history
        $stmt = $conn->prepare("
            INSERT INTO escrow_release_history (transaction_id, released_by, released_by_type, amount, notes, created_at) 
            VALUES (?, ?, 'admin', ?, ?, NOW())
        ");
        $stmt->bind_param("iids", $transaction_id, $admin_id, $refund_amount, $notes);
        $stmt->execute();
        
        // Add wallet transaction for buyer
        $conn->query("
            INSERT INTO wallet_transactions (user_id, amount, type, description, created_at) 
            VALUES ({$transaction['buyer_id']}, $refund_amount, 'deposit', 
                   'Refund for: {$transaction['title']}', NOW())
        ");
        
        // Add timeline
        addTransactionTimeline($conn, $transaction_id, 'refunded', 
            "Refund of " . formatMoney($refund_amount) . " processed to buyer", $admin_id);
        
        // Notify buyer
        $notif_stmt = $conn->prepare("
            INSERT INTO notifications (user_id, title, message, created_at) 
            VALUES (?, '💰 Refund Processed', 'A refund of " . formatMoney($refund_amount) . " has been issued for {$transaction['title']}.', NOW())
        ");
        $notif_stmt->bind_param("i", $transaction['buyer_id']);
        $notif_stmt->execute();
        
        // Notify seller
        $notif_stmt2 = $conn->prepare("
            INSERT INTO notifications (user_id, title, message, created_at) 
            VALUES (?, '⚠️ Transaction Cancelled', 'Transaction for {$transaction['title']} has been cancelled and buyer refunded.', NOW())
        ");
        $notif_stmt2->bind_param("i", $transaction['seller_id']);
        $notif_stmt2->execute();
        
        $conn->commit();
        
        return ['success' => true, 'amount' => $refund_amount];
        
    } catch (Exception $e) {
        $conn->rollback();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}


?>

BRS/includes/functions.php

<?php
// includes/functions.php - Complete with all needed functions

require_once __DIR__ . '/../config/database.php';

function formatMoney($amount) {
    return number_format($amount, 2) . ' ETB';
}

function getStatusBadge($status) {
    $badges = [
        'pending' => '<span class="badge badge-warning">Pending</span>',
        'pending_deposit' => '<span class="badge badge-warning">Pending Deposit</span>',
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

// Get setting from database
function getSetting($key, $default = null) {
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $conn->close();
        return $row['setting_value'];
    }
    
    $conn->close();
    
    // Default settings
    $defaults = [
        'deposit_percent' => 30,
        'commission_percent' => 15,
        'escrow_days' => 14,
        'site_name' => 'Ethio Brokerplace',
        'min_withdrawal' => 100,
        'max_withdrawal' => 100000,
        'maintenance_mode' => 0
    ];
    
    return $defaults[$key] ?? $default;
}

function updateSetting($key, $value) {
    $conn = getDbConnection();
    $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_at) 
                            VALUES (?, ?, NOW()) 
                            ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()");
    $stmt->bind_param("sss", $key, $value, $value);
    $result = $stmt->execute();
    $conn->close();
    return $result;
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

function logAdminAction($conn, $admin_id, $action, $target_type, $target_id, $details, $ip) {
    $stmt = $conn->prepare("INSERT INTO admin_logs (admin_id, action, target_type, target_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ississ", $admin_id, $action, $target_type, $target_id, $details, $ip);
    $stmt->execute();
}

BRS/includes/layout.php

<?php
// includes/layout.php - Complete Layout with Negotiations Menu

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    header('Location: /broker_system/auth/login.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/chat_functions.php';

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$user_email = $_SESSION['user_email'];
$user_role = $_SESSION['user_role'];

// Get unread notifications count
$notif_result = $conn->query("SELECT COUNT(*) as count FROM notifications WHERE user_id = $user_id AND is_read = 0");
$notifications_count = ($notif_result && $notif_result->num_rows > 0) ? $notif_result->fetch_assoc()['count'] : 0;

// Get unread chat messages count
$unread_chat_count = getUnreadMessageCount($conn, $user_id);

// Get pending rental bookings count (for property owners)
$pending_rentals_count = 0;
$rental_check = $conn->query("
    SELECT COUNT(*) as count 
    FROM rental_bookings 
    WHERE owner_id = $user_id 
    AND status = 'pending'
");
if ($rental_check && $rental_check->num_rows > 0) {
    $pending_rentals_count = $rental_check->fetch_assoc()['count'];
}

// Get pending legal transactions count
$legal_result = $conn->query("
    SELECT COUNT(*) as count FROM transactions t
    WHERE (t.buyer_id = $user_id OR t.seller_id = $user_id)
    AND t.status = 'deposits_complete'
    AND ((t.buyer_legal_confirmed = 0 AND t.buyer_id = $user_id) OR
         (t.seller_legal_confirmed = 0 AND t.seller_id = $user_id))
");
$pending_legal_count = ($legal_result && $legal_result->num_rows > 0) ? $legal_result->fetch_assoc()['count'] : 0;

// Get pending negotiations count
$pending_negotiations = $conn->query("
    SELECT COUNT(*) as count FROM listing_negotiations 
    WHERE seller_id = $user_id AND status IN ('under_review', 'commission_proposed', 'counter_offer_sent')
");
$pending_negotiations_count = ($pending_negotiations && $pending_negotiations->num_rows > 0) ? $pending_negotiations->fetch_assoc()['count'] : 0;

// Get recent notifications for dropdown
$recent_notifications = $conn->query("
    SELECT * FROM notifications 
    WHERE user_id = $user_id 
    ORDER BY created_at DESC 
    LIMIT 5
");

$conn->close();

// Get current page for active menu highlighting
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title><?php echo $page_title ?? 'Dashboard'; ?> - Ethio Brokerplace</title>
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
            background: #f1f5f9;
            overflow-x: hidden;
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
            z-index: 1000;
            overflow-y: auto;
            overflow-x: hidden;
            box-shadow: 4px 0 20px rgba(0,0,0,0.05);
        }
        
        /* Custom Scrollbar */
        .sidebar::-webkit-scrollbar { width: 4px; }
        .sidebar::-webkit-scrollbar-track { background: rgba(255,255,255,0.05); border-radius: 10px; }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 10px; }
        .sidebar::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.3); }
        .sidebar { scrollbar-width: thin; scrollbar-color: rgba(255,255,255,0.2) rgba(255,255,255,0.05); }
        
        /* Collapsed Sidebar */
        .sidebar.collapsed { width: 80px; }
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
            margin-left: 80px;
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
        
        .top-bar-actions {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        /* Notification Dropdown */
        .notification-dropdown { position: relative; }
        .notification-icon {
            position: relative;
            cursor: pointer;
            padding: 8px;
            border-radius: 50%;
            transition: background 0.3s;
        }
        .notification-icon:hover { background: #f1f5f9; }
        .notification-badge {
            position: absolute;
            top: 0;
            right: 0;
            background: #ef4444;
            color: white;
            font-size: 10px;
            padding: 2px 5px;
            border-radius: 10px;
        }
        .dropdown-menu {
            position: absolute;
            top: 100%;
            right: 0;
            width: 350px;
            background: white;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            display: none;
            z-index: 1000;
            margin-top: 8px;
        }
        .dropdown-menu.show { display: block; animation: dropdownFade 0.3s ease; }
        @keyframes dropdownFade {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .dropdown-header {
            padding: 12px 16px;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .dropdown-header h4 { font-size: 14px; font-weight: 600; }
        .dropdown-header a { font-size: 11px; color: #667eea; text-decoration: none; cursor: pointer; }
        .notification-item {
            padding: 12px 16px;
            border-bottom: 1px solid #f1f5f9;
            cursor: pointer;
            transition: background 0.3s;
        }
        .notification-item:hover { background: #f8fafc; }
        .notification-item.unread { background: #eef2ff; }
        .notification-title { font-size: 13px; font-weight: 600; margin-bottom: 4px; }
        .notification-message { font-size: 11px; color: #64748b; }
        .notification-time { font-size: 10px; color: #94a3b8; margin-top: 4px; }
        
        /* User Dropdown */
        .user-dropdown { position: relative; cursor: pointer; }
        .user-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
        }
        .user-menu {
            position: absolute;
            top: 100%;
            right: 0;
            width: 220px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            display: none;
            margin-top: 8px;
            z-index: 1000;
        }
        .user-menu.show { display: block; }
        .user-menu-item {
            padding: 10px 16px;
            display: flex;
            align-items: center;
            gap: 10px;
            color: #334155;
            text-decoration: none;
            transition: background 0.3s;
        }
        .user-menu-item:hover { background: #f1f5f9; }
        
        .container {
            padding: 28px;
        }
        
        /* Responsive */
        @media (max-width: 1024px) {
            .sidebar { width: 80px; }
            .sidebar .logo-text,
            .sidebar .menu-label,
            .sidebar .profile-info,
            .sidebar .section-header { display: none; }
            .sidebar .menu-item { justify-content: center; padding: 12px; }
            .sidebar .menu-item i { margin-right: 0; font-size: 20px; }
            .main-content { margin-left: 80px; }
        }
        
        @media (max-width: 768px) {
            .sidebar { 
                transform: translateX(-100%);
                width: 280px;
            }
            .sidebar.mobile-open { transform: translateX(0); }
            .sidebar.mobile-open .logo-text,
            .sidebar.mobile-open .menu-label,
            .sidebar.mobile-open .profile-info,
            .sidebar.mobile-open .section-header { display: block; }
            .sidebar.mobile-open .menu-item { justify-content: flex-start; padding: 10px 14px; }
            .sidebar.mobile-open .menu-item i { margin-right: 12px; font-size: 18px; }
            .main-content { margin-left: 0; }
            .top-bar { padding: 12px 20px; }
            .page-title { font-size: 20px; }
            .container { padding: 20px; }
            .dropdown-menu { width: 300px; right: -50px; }
        }
    </style>
</head>
<body>
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
            <!-- Dashboard -->
            <a href="/broker_system/user/dashboard.php" class="menu-item <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
                <i class="fas fa-home"></i>
                <span class="menu-label">Dashboard</span>
            </a>
            
            <!-- Browse -->
            <a href="/broker_system/user/browse.php" class="menu-item <?php echo $current_page == 'browse.php' ? 'active' : ''; ?>">
                <i class="fas fa-search"></i>
                <span class="menu-label">Browse</span>
            </a>
            
            <!-- My Listings -->
            <a href="/broker_system/user/listings.php" class="menu-item <?php echo $current_page == 'listings.php' ? 'active' : ''; ?>">
                <i class="fas fa-box"></i>
                <span class="menu-label">My Listings</span>
            </a>
            
            <!-- NEGOTIATIONS - NEW MENU ITEM -->
            <a href="/broker_system/user/negotiations.php" class="menu-item <?php echo $current_page == 'negotiations.php' ? 'active' : ''; ?>">
                <i class="fas fa-handshake"></i>
                <span class="menu-label">Negotiations</span>
                <?php if ($pending_negotiations_count > 0): ?>
                    <span class="badge-count"><?php echo $pending_negotiations_count; ?></span>
                <?php endif; ?>
            </a>
            
            <!-- MY RENTERS -->
            <a href="/broker_system/user/owner_bookings.php" class="menu-item <?php echo $current_page == 'owner_bookings.php' ? 'active' : ''; ?>">
                <i class="fas fa-users"></i>
                <span class="menu-label">My Renters</span>
                <?php if ($pending_rentals_count > 0): ?>
                    <span class="badge-count"><?php echo $pending_rentals_count; ?></span>
                <?php endif; ?>
            </a>
            
            <!-- Wallet -->
            <a href="/broker_system/user/wallet.php" class="menu-item <?php echo $current_page == 'wallet.php' ? 'active' : ''; ?>">
                <i class="fas fa-wallet"></i>
                <span class="menu-label">Wallet</span>
            </a>
            
            <!-- Messages / Chat -->
            <a href="/broker_system/user/chat.php" class="menu-item <?php echo $current_page == 'chat.php' ? 'active' : ''; ?>">
                <i class="fas fa-comments"></i>
                <span class="menu-label">Messages</span>
                <?php if ($unread_chat_count > 0): ?>
                    <span class="badge-count"><?php echo $unread_chat_count; ?></span>
                <?php endif; ?>
            </a>
            
            <!-- Notifications -->
            <a href="/broker_system/user/notifications.php" class="menu-item <?php echo $current_page == 'notifications.php' ? 'active' : ''; ?>">
                <i class="fas fa-bell"></i>
                <span class="menu-label">Notifications</span>
                <?php if ($notifications_count > 0): ?>
                    <span class="badge-count"><?php echo $notifications_count; ?></span>
                <?php endif; ?>
            </a>
            
            <!-- Activity Section -->
            <div class="section-header">Activity</div>
            
            <!-- Transactions -->
            <a href="/broker_system/user/transactions.php" class="menu-item <?php echo $current_page == 'transactions.php' ? 'active' : ''; ?>">
                <i class="fas fa-exchange-alt"></i>
                <span class="menu-label">Transactions</span>
            </a>
            
            <!-- Legal Process -->
            <a href="/broker_system/user/legal_process.php" class="menu-item <?php echo $current_page == 'legal_process.php' ? 'active' : ''; ?>">
                <i class="fas fa-gavel"></i>
                <span class="menu-label">Legal Process</span>
                <?php if ($pending_legal_count > 0): ?>
                    <span class="badge-count"><?php echo $pending_legal_count; ?></span>
                <?php endif; ?>
            </a>
        </ul>
        
        <div class="sidebar-footer">
            <!-- Profile -->
            <a href="/broker_system/user/profile.php" class="profile-item">
                <div class="profile-avatar"><?php echo strtoupper(substr($user_name, 0, 1)); ?></div>
                <div class="profile-info">
                    <div class="profile-name"><?php echo htmlspecialchars($user_name); ?></div>
                    <div class="profile-email"><?php echo htmlspecialchars($user_email); ?></div>
                </div>
            </a>
            
            <!-- Settings -->
            <a href="/broker_system/user/settings.php" class="menu-item" style="margin-top: 4px;">
                <i class="fas fa-cog"></i>
                <span class="menu-label">Settings</span>
            </a>
            
            <!-- Logout -->
            <a href="/broker_system/auth/logout.php" class="menu-item" style="margin-top: 4px;">
                <i class="fas fa-sign-out-alt logout-icon"></i>
                <span class="menu-label">Logout</span>
            </a>
        </div>
    </div>
    
    <!-- MAIN CONTENT -->
    <div class="main-content" id="mainContent">
        <div class="top-bar">
            <h1 class="page-title"><?php echo $page_title ?? 'Dashboard'; ?></h1>
            <div class="top-bar-actions">
                <!-- Notifications Dropdown -->
                <div class="notification-dropdown">
                    <div class="notification-icon" id="notificationIcon">
                        <i class="fas fa-bell"></i>
                        <?php if ($notifications_count > 0): ?>
                            <span class="notification-badge"><?php echo $notifications_count; ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="dropdown-menu" id="notificationDropdown">
                        <div class="dropdown-header">
                            <h4>Notifications</h4>
                            <a href="/broker_system/user/notifications.php">View all</a>
                        </div>
                        <div id="notificationList">
                            <?php if ($recent_notifications && $recent_notifications->num_rows > 0): ?>
                                <?php while($notif = $recent_notifications->fetch_assoc()): ?>
                                    <div class="notification-item <?php echo $notif['is_read'] ? '' : 'unread'; ?>" onclick="location.href='<?php echo $notif['link'] ?? 'notifications.php'; ?>'">
                                        <div class="notification-title"><?php echo htmlspecialchars($notif['title']); ?></div>
                                        <div class="notification-message"><?php echo htmlspecialchars(substr($notif['message'], 0, 80)); ?></div>
                                        <div class="notification-time"><?php echo timeAgo($notif['created_at']); ?></div>
                                    </div>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <div class="notification-item">No new notifications</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- User Dropdown -->
                <div class="user-dropdown">
                    <div class="user-avatar" id="userAvatar"><?php echo strtoupper(substr($user_name, 0, 1)); ?></div>
                    <div class="user-menu" id="userMenu">
                        <a href="/broker_system/user/profile.php" class="user-menu-item"><i class="fas fa-user"></i> Profile</a>
                        <a href="/broker_system/user/wallet.php" class="user-menu-item"><i class="fas fa-wallet"></i> Wallet</a>
                        <a href="/broker_system/user/notifications.php" class="user-menu-item"><i class="fas fa-bell"></i> Notifications</a>
                        <a href="/broker_system/user/negotiations.php" class="user-menu-item"><i class="fas fa-handshake"></i> Negotiations</a>
                        <a href="/broker_system/user/settings.php" class="user-menu-item"><i class="fas fa-cog"></i> Settings</a>
                        <hr style="margin: 8px 0; border-color: #f1f5f9;">
                        <a href="/broker_system/user/owner_bookings.php" class="user-menu-item"><i class="fas fa-users"></i> My Renters</a>
                        <hr style="margin: 8px 0; border-color: #f1f5f9;">
                        <a href="/broker_system/auth/logout.php" class="user-menu-item"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="container">
            <?php echo $content ?? ''; ?>
        </div>
    </div>
    
    <script>
        // Sidebar collapse toggle
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const collapseBtn = document.getElementById('collapseBtn');
        
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
        
        // Notification dropdown
        const notificationIcon = document.getElementById('notificationIcon');
        const notificationDropdown = document.getElementById('notificationDropdown');
        
        if (notificationIcon) {
            notificationIcon.addEventListener('click', function(e) {
                e.stopPropagation();
                notificationDropdown.classList.toggle('show');
                if (userMenu) userMenu.classList.remove('show');
            });
        }
        
        // User dropdown
        const userAvatar = document.getElementById('userAvatar');
        const userMenu = document.getElementById('userMenu');
        
        if (userAvatar) {
            userAvatar.addEventListener('click', function(e) {
                e.stopPropagation();
                userMenu.classList.toggle('show');
                if (notificationDropdown) notificationDropdown.classList.remove('show');
            });
        }
        
        // Close dropdowns when clicking outside
        document.addEventListener('click', function() {
            if (notificationDropdown) notificationDropdown.classList.remove('show');
            if (userMenu) userMenu.classList.remove('show');
        });
        
        // Mobile sidebar toggle
        function toggleMobileSidebar() {
            sidebar.classList.toggle('mobile-open');
        }
        
        // Mark notification as read when clicked
        document.querySelectorAll('.notification-item').forEach(item => {
            item.addEventListener('click', function() {
                this.classList.remove('unread');
            });
        });
    </script>
</body>
</html>

BRS/includes/negotiation_functions.php

<?php
// ============================================
// FILE: broker_system/includes/negotiation_functions.php
// ============================================
// Core Negotiation Engine Functions

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';

/**
 * Create a new listing negotiation record
 */
function createListingNegotiation($conn, $listing_id, $seller_id) {
    $stmt = $conn->prepare("
        INSERT INTO listing_negotiations (
            listing_id, seller_id, status, created_at, updated_at
        ) VALUES (?, ?, 'under_review', NOW(), NOW())
    ");
    $stmt->bind_param("ii", $listing_id, $seller_id);
    $stmt->execute();
    return $conn->insert_id;
}

/**
 * Get negotiation details with related data
 */
function getNegotiationDetails($conn, $negotiation_id) {
    $stmt = $conn->prepare("
        SELECT ln.*, 
               l.title as listing_title,
               l.type as listing_type,
               l.description as listing_description,
               l.price as listing_price,
               l.category_id,
               u.full_name as seller_name,
               u.email as seller_email
        FROM listing_negotiations ln
        JOIN listings l ON ln.listing_id = l.id
        JOIN users u ON ln.seller_id = u.id
        WHERE ln.id = ?
    ");
    $stmt->bind_param("i", $negotiation_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

/**
 * Get all negotiations for a user
 */
function getUserNegotiations($conn, $user_id, $status = null) {
    $sql = "
        SELECT ln.*, l.title, l.type, l.price,
               (SELECT COUNT(*) FROM negotiation_messages nm WHERE nm.negotiation_id = ln.id AND nm.is_read = 0 AND nm.sender_type != 'seller') as unread_count
        FROM listing_negotiations ln
        JOIN listings l ON ln.listing_id = l.id
        WHERE ln.seller_id = ?
    ";
    if ($status) {
        $sql .= " AND ln.status = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("is", $user_id, $status);
    } else {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
    }
    $stmt->execute();
    return $stmt->get_result();
}

/**
 * Get all negotiations for admin
 */
function getAdminNegotiations($conn, $status = null) {
    $sql = "
        SELECT ln.*, l.title, l.type, l.price, u.full_name as seller_name, u.email as seller_email,
               (SELECT COUNT(*) FROM negotiation_messages nm WHERE nm.negotiation_id = ln.id AND nm.is_read = 0 AND nm.sender_type != 'admin') as unread_count
        FROM listing_negotiations ln
        JOIN listings l ON ln.listing_id = l.id
        JOIN users u ON ln.seller_id = u.id
        WHERE ln.status NOT IN ('published', 'cancelled')
    ";
    if ($status) {
        $sql .= " AND ln.status = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $status);
    } else {
        $stmt = $conn->prepare($sql);
    }
    $stmt->execute();
    return $stmt->get_result();
}

/**
 * Calculate smart commission based on listing value and category
 */
function calculateSmartCommission($price, $type, $seller_trust_score = 0) {
    // Base commission by type
    $base_rates = [
        'rental' => ['min' => 5, 'max' => 10],
        'product' => ['min' => 3, 'max' => 8],
        'job' => ['min' => 4, 'max' => 12]
    ];
    
    $rate_range = $base_rates[$type] ?? ['min' => 5, 'max' => 10];
    
    // Value-based adjustment
    if ($price < 500000) {
        $rate = $rate_range['max'];
    } elseif ($price >= 500000 && $price <= 2000000) {
        $rate = ($rate_range['min'] + $rate_range['max']) / 2;
    } else {
        $rate = $rate_range['min'];
    }
    
    // Seller trust adjustment (reduce commission for trusted sellers)
    if ($seller_trust_score >= 80) {
        $rate = max($rate_range['min'], $rate - 1);
    } elseif ($seller_trust_score >= 60) {
        $rate = max($rate_range['min'], $rate - 0.5);
    }
    
    return round($rate, 1);
}

/**
 * Calculate recommended deposit
 */
function calculateRecommendedDeposit($price, $type) {
    $deposit_rates = [
        'rental' => 30,
        'product' => 25,
        'job' => 20
    ];
    
    $rate = $deposit_rates[$type] ?? 25;
    $deposit = $price * ($rate / 100);
    
    // Cap deposit
    $max_deposit = 50000;
    return min($deposit, $max_deposit);
}

/**
 * Send negotiation message
 */
function sendNegotiationMessage($conn, $negotiation_id, $sender_id, $sender_type, $message) {
    $stmt = $conn->prepare("
        INSERT INTO negotiation_messages (negotiation_id, sender_id, sender_type, message, is_read, created_at) 
        VALUES (?, ?, ?, ?, 0, NOW())
    ");
    $stmt->bind_param("iiss", $negotiation_id, $sender_id, $sender_type, $message);
    $stmt->execute();
    $message_id = $conn->insert_id;
    
    // Update negotiation updated_at
    $conn->query("UPDATE listing_negotiations SET updated_at = NOW() WHERE id = $negotiation_id");
    
    return $message_id;
}

/**
 * Get negotiation messages
 */
function getNegotiationMessages($conn, $negotiation_id, $user_id, $user_type) {
    $stmt = $conn->prepare("
        SELECT nm.*, 
               CASE WHEN nm.sender_type = 'admin' THEN 'Administrator' ELSE u.full_name END as sender_name
        FROM negotiation_messages nm
        LEFT JOIN users u ON nm.sender_id = u.id AND nm.sender_type = 'seller'
        WHERE nm.negotiation_id = ?
        ORDER BY nm.created_at ASC
    ");
    $stmt->bind_param("i", $negotiation_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    // Mark messages as read
    $conn->query("
        UPDATE negotiation_messages 
        SET is_read = 1 
        WHERE negotiation_id = $negotiation_id AND sender_type != '$user_type'
    ");
    
    $messages = [];
    while ($row = $result->fetch_assoc()) {
        $messages[] = $row;
    }
    return $messages;
}

/**
 * Propose commission and deposit (Admin action)
 */
function proposeCommissionDeposit($conn, $negotiation_id, $commission_percent, $deposit_amount, $featured_fee = 0, $notes = '') {
    $stmt = $conn->prepare("
        UPDATE listing_negotiations 
        SET proposed_commission = ?, 
            proposed_deposit = ?, 
            featured_listing_fee = ?,
            admin_notes = ?,
            status = 'commission_proposed',
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param("dddsi", $commission_percent, $deposit_amount, $featured_fee, $notes, $negotiation_id);
    $stmt->execute();
    
    // Get seller info for notification
    $negotiation = getNegotiationDetails($conn, $negotiation_id);
    if ($negotiation) {
        sendNegotiationMessage($conn, $negotiation_id, 0, 'system', 
            "Admin has proposed " . $commission_percent . "% commission and " . formatMoney($deposit_amount) . " deposit for your listing.");
    }
    
    return $stmt->affected_rows > 0;
}

/**
 * Seller counter-offer
 */
function sendCounterOffer($conn, $negotiation_id, $commission_percent, $deposit_amount, $message = '') {
    $stmt = $conn->prepare("
        UPDATE listing_negotiations 
        SET counter_commission = ?, 
            counter_deposit = ?,
            counter_message = ?,
            status = 'counter_offer_sent',
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param("ddsi", $commission_percent, $deposit_amount, $message, $negotiation_id);
    $stmt->execute();
    
    // Send system message
    sendNegotiationMessage($conn, $negotiation_id, 0, 'system', 
        "Seller has sent a counter-offer: " . $commission_percent . "% commission, " . formatMoney($deposit_amount) . " deposit.");
    
    return $stmt->affected_rows > 0;
}

/**
 * Accept agreement (Seller action)
 */
function acceptAgreement($conn, $negotiation_id) {
    $stmt = $conn->prepare("
        UPDATE listing_negotiations 
        SET status = 'agreement_accepted',
            accepted_at = NOW(),
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param("i", $negotiation_id);
    $stmt->execute();
    
    // Send system message
    sendNegotiationMessage($conn, $negotiation_id, 0, 'system', 
        "Seller has accepted the agreement. Deposit payment is now required to publish the listing.");
    
    // Get listing details
    $negotiation = getNegotiationDetails($conn, $negotiation_id);
    if ($negotiation) {
        // Update listing with proposed commission and deposit
        $final_commission = $negotiation['counter_commission'] ?: $negotiation['proposed_commission'];
        $final_deposit = $negotiation['counter_deposit'] ?: $negotiation['proposed_deposit'];
        
        $conn->query("
            UPDATE listings 
            SET admin_commission_percent = $final_commission,
                admin_deposit_percent = ($final_deposit / price) * 100,
                status = 'pending',
                approval_status = 'approved'
            WHERE id = {$negotiation['listing_id']}
        ");
    }
    
    return $stmt->affected_rows > 0;
}

/**
 * Reject agreement (Seller action)
 */
function rejectAgreement($conn, $negotiation_id, $reason = '') {
    $stmt = $conn->prepare("
        UPDATE listing_negotiations 
        SET status = 'rejected',
            rejection_reason = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param("si", $reason, $negotiation_id);
    $stmt->execute();
    
    sendNegotiationMessage($conn, $negotiation_id, 0, 'system', 
        "Seller has rejected the agreement. Reason: " . ($reason ?: 'Not specified'));
    
    return $stmt->affected_rows > 0;
}

/**
 * Admin approve after payment verification
 */
function approveListingPublish($conn, $negotiation_id) {
    $negotiation = getNegotiationDetails($conn, $negotiation_id);
    if (!$negotiation) return false;
    
    $conn->begin_transaction();
    
    try {
        // Update negotiation status
        $conn->query("
            UPDATE listing_negotiations 
            SET status = 'published', 
                published_at = NOW(),
                updated_at = NOW()
            WHERE id = $negotiation_id
        ");
        
        // Update listing status
        $conn->query("
            UPDATE listings 
            SET status = 'active', 
                approval_status = 'approved',
                updated_at = NOW()
            WHERE id = {$negotiation['listing_id']}
        ");
        
        // Record payment if paid
        $final_commission = $negotiation['counter_commission'] ?: $negotiation['proposed_commission'];
        $final_deposit = $negotiation['counter_deposit'] ?: $negotiation['proposed_deposit'];
        $total_payment = $final_deposit + ($negotiation['featured_listing_fee'] ?? 0);
        
        // Create payment record if not exists
        $check_payment = $conn->query("
            SELECT id FROM payments 
            WHERE transaction_id IN (SELECT id FROM transactions WHERE listing_id = {$negotiation['listing_id']})
            AND type = 'deposit_seller'
        ");
        
        if ($check_payment->num_rows == 0) {
            $conn->query("
                INSERT INTO payments (user_id, amount, type, status, created_at) 
                VALUES ({$negotiation['seller_id']}, $total_payment, 'deposit_seller', 'pending', NOW())
            ");
        }
        
        $conn->commit();
        sendNegotiationMessage($conn, $negotiation_id, 0, 'system', 
            "🎉 Congratulations! Your listing has been published and is now visible to buyers.");
        
        return true;
    } catch (Exception $e) {
        $conn->rollback();
        return false;
    }
}

/**
 * Get negotiation timeline
 */
function getNegotiationTimeline($conn, $negotiation_id) {
    $negotiation = getNegotiationDetails($conn, $negotiation_id);
    if (!$negotiation) return [];
    
    $timeline = [
        ['status' => 'draft', 'label' => 'Listing Created', 'date' => $negotiation['created_at'], 'completed' => true],
        ['status' => 'under_review', 'label' => 'Under Review', 'date' => $negotiation['created_at'], 'completed' => $negotiation['status'] != 'draft']
    ];
    
    if ($negotiation['proposed_commission']) {
        $timeline[] = ['status' => 'commission_proposed', 'label' => 'Commission Proposed', 'date' => $negotiation['updated_at'], 
            'completed' => in_array($negotiation['status'], ['commission_proposed', 'counter_offer_sent', 'agreement_accepted', 'agreement_pending', 'deposit_pending', 'published'])];
    }
    
    if ($negotiation['counter_commission']) {
        $timeline[] = ['status' => 'counter_offer_received', 'label' => 'Counter Offer', 'date' => $negotiation['updated_at'],
            'completed' => in_array($negotiation['status'], ['agreement_accepted', 'agreement_pending', 'deposit_pending', 'published'])];
    }
    
    if ($negotiation['accepted_at']) {
        $timeline[] = ['status' => 'agreement_accepted', 'label' => 'Agreement Accepted', 'date' => $negotiation['accepted_at'],
            'completed' => in_array($negotiation['status'], ['deposit_pending', 'published'])];
    }
    
    if ($negotiation['deposit_paid_at']) {
        $timeline[] = ['status' => 'deposit_paid', 'label' => 'Deposit Paid', 'date' => $negotiation['deposit_paid_at'],
            'completed' => in_array($negotiation['status'], ['published'])];
    }
    
    if ($negotiation['published_at']) {
        $timeline[] = ['status' => 'published', 'label' => 'Listing Published', 'date' => $negotiation['published_at'],
            'completed' => true];
    }
    
    return $timeline;
}

/**
 * Update payment status for negotiation
 */
function updateNegotiationPaymentStatus($conn, $negotiation_id, $status) {
    $field = ($status == 'paid') ? 'deposit_paid_at' : '';
    $negotiation_status = ($status == 'paid') ? 'payment_verified' : 'agreement_accepted';
    
    $sql = "UPDATE listing_negotiations SET status = '$negotiation_status'";
    if ($field) {
        $sql .= ", $field = NOW()";
    }
    $sql .= " WHERE id = $negotiation_id";
    
    $conn->query($sql);
    
    if ($status == 'paid') {
        sendNegotiationMessage($conn, $negotiation_id, 0, 'system', 
            "Deposit payment has been verified. Your listing is being prepared for publication.");
    }
    
    return true;
}
?>

BRS/includes/payment_code.php

<?php
// includes/payment_code.php - Shared Telebirr payment code expiry (10 minutes)

if (!defined('PAYMENT_CODE_EXPIRY_MINUTES')) {
    define('PAYMENT_CODE_EXPIRY_MINUTES', 10);
}

function paymentCodeMaxSeconds() {
    return (int) PAYMENT_CODE_EXPIRY_MINUTES * 60;
}

function ensurePaymentCodeTimezone($conn) {
    date_default_timezone_set('Africa/Addis_Ababa');
    if ($conn) {
        $conn->query("SET time_zone = '+03:00'");
    }
}

/**
 * Expire pending codes that are past due or legacy 30-minute windows.
 */
function expireLegacyLongPaymentCodes($conn, $transaction_id, $user_id, $type) {
    $transaction_id = (int) $transaction_id;
    $user_id = (int) $user_id;
    $type = $conn->real_escape_string($type);
    $max_sec = paymentCodeMaxSeconds();

    $conn->query("
        UPDATE payment_codes
        SET status = 'expired'
        WHERE transaction_id = $transaction_id
          AND user_id = $user_id
          AND type = '$type'
          AND status = 'pending'
          AND (
              expires_at <= NOW()
              OR TIMESTAMPDIFF(SECOND, NOW(), expires_at) > $max_sec
          )
    ");
}

function normalizePaymentCodeSeconds($seconds) {
    $seconds = max(0, (int) $seconds);
    return min(paymentCodeMaxSeconds(), $seconds);
}

/**
 * @return array{id:int,code:string,amount:float,seconds_remaining:int}|null
 */
function findValidPaymentCode($conn, $transaction_id, $user_id, $type) {
    ensurePaymentCodeTimezone($conn);
    expireLegacyLongPaymentCodes($conn, $transaction_id, $user_id, $type);

    $transaction_id = (int) $transaction_id;
    $user_id = (int) $user_id;
    $type = $conn->real_escape_string($type);
    $max_sec = paymentCodeMaxSeconds();

    $result = $conn->query("
        SELECT id, code, amount,
               TIMESTAMPDIFF(SECOND, NOW(), expires_at) AS seconds_remaining
        FROM payment_codes
        WHERE transaction_id = $transaction_id
          AND user_id = $user_id
          AND type = '$type'
          AND status = 'pending'
          AND expires_at > NOW()
          AND TIMESTAMPDIFF(SECOND, NOW(), expires_at) BETWEEN 1 AND $max_sec
        ORDER BY id DESC
        LIMIT 1
    ");

    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $row['seconds_remaining'] = normalizePaymentCodeSeconds($row['seconds_remaining']);
        return $row;
    }

    return null;
}

/**
 * @return array{code:string,code_id:int,amount:float,seconds_remaining:int,created:bool,expiry_minutes:int}
 */
function getOrCreatePaymentCode($conn, $transaction_id, $user_id, $amount, $type) {
    ensurePaymentCodeTimezone($conn);

    $existing = findValidPaymentCode($conn, $transaction_id, $user_id, $type);
    if ($existing) {
        return [
            'code' => $existing['code'],
            'code_id' => (int) $existing['id'],
            'amount' => (float) $existing['amount'],
            'seconds_remaining' => (int) $existing['seconds_remaining'],
            'created' => false,
            'expiry_minutes' => PAYMENT_CODE_EXPIRY_MINUTES,
        ];
    }

    expireLegacyLongPaymentCodes($conn, $transaction_id, $user_id, $type);

    $transaction_id = (int) $transaction_id;
    $user_id = (int) $user_id;
    $amount = (float) $amount;
    $mins = (int) PAYMENT_CODE_EXPIRY_MINUTES;
    $type_sql = $conn->real_escape_string($type);

    do {
        $code = str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT);
        $chk = $conn->prepare('SELECT id FROM payment_codes WHERE code = ? LIMIT 1');
        $chk->bind_param('s', $code);
        $chk->execute();
        $exists = $chk->get_result()->num_rows > 0;
        $chk->close();
    } while ($exists);

    $code_sql = $conn->real_escape_string($code);
    $conn->query("
        INSERT INTO payment_codes (code, transaction_id, amount, user_id, type, expires_at, status, created_at)
        VALUES ('$code_sql', $transaction_id, $amount, $user_id, '$type_sql', DATE_ADD(NOW(), INTERVAL $mins MINUTE), 'pending', NOW())
    ");
    $code_id = (int) $conn->insert_id;

    $sec_row = $conn->query("
        SELECT TIMESTAMPDIFF(SECOND, NOW(), expires_at) AS seconds_remaining
        FROM payment_codes WHERE id = $code_id
    ")->fetch_assoc();

    $seconds = normalizePaymentCodeSeconds($sec_row['seconds_remaining'] ?? ($mins * 60));

    return [
        'code' => $code,
        'code_id' => $code_id,
        'amount' => $amount,
        'seconds_remaining' => $seconds,
        'created' => true,
        'expiry_minutes' => $mins,
    ];
}

/**
 * Lookup a code for Telebirr / external verify (no session). Reactivates wrongly expired valid codes.
 *
 * @return array{ok:bool,error?:string,row?:array}
 */
function lookupPaymentCodeForExternalVerify($conn, $code) {
    ensurePaymentCodeTimezone($conn);
    $code = preg_replace('/[^0-9]/', '', (string) $code);
    if (strlen($code) !== 5) {
        return ['ok' => false, 'error' => 'Invalid code format. Must be 5 digits.'];
    }

    $code_sql = $conn->real_escape_string($code);
    $row = $conn->query("
        SELECT pc.*,
               COALESCE(l.title, CONCAT('Transaction #', t.id)) AS item_name,
               TIMESTAMPDIFF(SECOND, NOW(), pc.expires_at) AS seconds_remaining
        FROM payment_codes pc
        JOIN transactions t ON pc.transaction_id = t.id
        LEFT JOIN listings l ON t.listing_id = l.id
        WHERE pc.code = '$code_sql'
        LIMIT 1
    ")->fetch_assoc();

    if (!$row) {
        return ['ok' => false, 'error' => 'Invalid payment code. Please generate a code from your listing page.'];
    }

    $seconds = (int) $row['seconds_remaining'];
    $status = $row['status'];

    if ($status === 'used') {
        return ['ok' => false, 'error' => 'Code already used'];
    }

    if ($seconds <= 0) {
        if ($status === 'pending') {
            $conn->query("UPDATE payment_codes SET status = 'expired' WHERE id = " . (int) $row['id']);
        }
        return ['ok' => false, 'error' => 'Code expired. Please generate a new code on the website.'];
    }

    // Recover code if web polling marked it expired while still within the time window
    if ($status !== 'pending') {
        if ($status === 'expired') {
            $conn->query("UPDATE payment_codes SET status = 'pending' WHERE id = " . (int) $row['id']);
            $row['status'] = 'pending';
        } else {
            return ['ok' => false, 'error' => 'Code already ' . $status];
        }
    }

    $row['seconds_remaining'] = normalizePaymentCodeSeconds($seconds);
    return ['ok' => true, 'row' => $row];
}

/**
 * Status poll helper — do not expire sibling codes, only the row if truly past due.
 */
function refreshPaymentCodeExpiryState($conn, $code, $user_id) {
    ensurePaymentCodeTimezone($conn);
    $code = $conn->real_escape_string(preg_replace('/[^0-9]/', '', (string) $code));
    $user_id = (int) $user_id;

    $row = $conn->query("
        SELECT id, transaction_id, type, status,
               TIMESTAMPDIFF(SECOND, NOW(), expires_at) AS seconds_remaining
        FROM payment_codes
        WHERE code = '$code' AND user_id = $user_id
        LIMIT 1
    ")->fetch_assoc();

    if (!$row || $row['status'] !== 'pending') {
        return $row;
    }

    if ((int) $row['seconds_remaining'] <= 0) {
        $conn->query("UPDATE payment_codes SET status = 'expired' WHERE id = " . (int) $row['id']);
        $row['status'] = 'expired';
        $row['seconds_remaining'] = 0;
    } else {
        $row['seconds_remaining'] = normalizePaymentCodeSeconds($row['seconds_remaining']);
    }

    return $row;
}


BRS/includes/payment_confirm.php

<?php
// includes/payment_confirm.php - Record confirmed payment from a payment code

require_once __DIR__ . '/seller_listing_payment.php';
require_once __DIR__ . '/transaction_workflow.php';

/**
 * Confirm a pending payment code and apply business rules by payment type.
 *
 * @param mysqli $conn
 * @param string $code 5-digit Telebirr code
 * @param array $options user_id (optional session check), amount (optional override)
 * @return array
 */
function confirmPaymentByCode($conn, $code, array $options = []) {
    $code = preg_replace('/[^0-9]/', '', (string) $code);
    if (strlen($code) !== 5) {
        return ['success' => false, 'error' => 'Invalid payment code'];
    }

    $session_user_id = isset($options['user_id']) ? (int) $options['user_id'] : null;

    $stmt = $conn->prepare("
        SELECT pc.*,
               t.buyer_id, t.seller_id, t.total_amount, t.deposit_amount,
               t.commission_amount, t.remaining_balance, t.escrow_held,
               l.id AS listing_id, l.status AS listing_status, l.type AS listing_type, l.title AS listing_title
        FROM payment_codes pc
        JOIN transactions t ON pc.transaction_id = t.id
        JOIN listings l ON t.listing_id = l.id
        WHERE pc.code = ?
        LIMIT 1
    ");
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $pc = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$pc) {
        return ['success' => false, 'error' => 'Payment code not found'];
    }

    if ($session_user_id !== null && (int) $pc['user_id'] !== $session_user_id) {
        return ['success' => false, 'error' => 'This payment code belongs to another user'];
    }

    $existing = $conn->query("
        SELECT id, type FROM payments
        WHERE telebirr_code_5digit = '$code' AND status = 'confirmed'
        LIMIT 1
    ");
    if ($existing && $existing->num_rows > 0) {
        $paid = $existing->fetch_assoc();
        return [
            'success' => true,
            'already_confirmed' => true,
            'message' => 'Payment already confirmed',
            'payment_type' => $paid['type'],
            'transaction_id' => (int) $pc['transaction_id'],
            'listing_id' => (int) $pc['listing_id'],
        ];
    }

    if ($pc['status'] === 'used') {
        return ['success' => false, 'error' => 'Payment code already used'];
    }

    $seconds_left = (int) $conn->query("
        SELECT TIMESTAMPDIFF(SECOND, NOW(), '{$pc['expires_at']}') AS s
    ")->fetch_assoc()['s'];

    if ($seconds_left <= 0 && $pc['status'] === 'pending') {
        $conn->query("UPDATE payment_codes SET status = 'expired' WHERE id = {$pc['id']}");
        return ['success' => false, 'error' => 'Payment code expired'];
    }

    $tid = (int) $pc['transaction_id'];
    $lid = (int) $pc['listing_id'];
    $uid = (int) $pc['user_id'];
    $amount = isset($options['amount']) ? (float) $options['amount'] : (float) $pc['amount'];
    $payment_type = $pc['type'];

    $conn->begin_transaction();

    try {
        $ins = $conn->prepare("
            INSERT INTO payments (
                transaction_id, user_id, amount, type,
                telebirr_code_5digit, status, confirmed_at, created_at
            ) VALUES (?, ?, ?, ?, ?, 'confirmed', NOW(), NOW())
        ");
        $ins->bind_param('iidss', $tid, $uid, $amount, $payment_type, $code);
        $ins->execute();
        $ins->close();

        $conn->query("UPDATE payment_codes SET status = 'used' WHERE id = {$pc['id']}");

        $response = [
            'success' => true,
            'message' => 'Payment confirmed',
            'payment_type' => $payment_type,
            'transaction_id' => $tid,
            'listing_id' => $lid,
        ];

        if ($payment_type === 'deposit_seller') {
            $deposit_amount = (float) $pc['deposit_amount'];
            $total_amount = (float) $pc['total_amount'];
            $new_remaining = max(0, round($total_amount - $deposit_amount, 2));

            $conn->query("
                UPDATE transactions
                SET escrow_held = escrow_held + $amount,
                    remaining_balance = $new_remaining,
                    status = 'deposits_complete'
                WHERE id = $tid
            ");
            $conn->query("UPDATE listings SET status = 'active' WHERE id = $lid");
            updateListingSellerPaymentStatus($conn, $lid, 'deposit_paid');

            $response['message'] = 'Deposit confirmed. Listing is now active.';
            $response['listing_activated'] = true;
            $response['payment_status'] = 'deposit_paid';

        } elseif ($payment_type === 'remaining_balance') {
            $txn_row = $conn->query("SELECT remaining_balance FROM transactions WHERE id = $tid")->fetch_assoc();
            $new_remaining = max(0, round((float) ($txn_row['remaining_balance'] ?? 0) - $amount, 2));
            $status_label = $new_remaining > 0 ? 'partially_paid' : 'fully_paid';

            $conn->query("
                UPDATE transactions
                SET escrow_held = escrow_held + $amount,
                    remaining_balance = $new_remaining
                WHERE id = $tid
            ");
            if ($lid && (int) $pc['user_id'] === (int) $pc['seller_id']) {
                updateListingSellerPaymentStatus($conn, $lid, $status_label);
            }

            logTransactionAction($conn, $tid, 'remaining_payment',
                'Remaining balance payment: ' . formatMoney($amount), $uid);

            $response['message'] = $new_remaining > 0
                ? 'Partial remaining balance confirmed'
                : 'Remaining balance paid in full';
            $response['payment_status'] = $status_label;
            $response['remaining_balance'] = $new_remaining;
            $response['is_fully_paid'] = $new_remaining <= 0;

        } elseif ($payment_type === 'deposit_buyer') {
            if (!function_exists('formatMoney')) {
                require_once __DIR__ . '/functions.php';
            }

            $has_escrow_status = $conn->query("SHOW COLUMNS FROM transactions LIKE 'escrow_status'");
            if ($has_escrow_status && $has_escrow_status->num_rows > 0) {
                $conn->query("
                    UPDATE transactions
                    SET escrow_held = escrow_held + $amount,
                        status = 'escrow_active',
                        escrow_status = 'active'
                    WHERE id = $tid
                ");
            } else {
                $conn->query("
                    UPDATE transactions
                    SET escrow_held = escrow_held + $amount,
                        status = 'in_progress'
                    WHERE id = $tid
                ");
            }

            $booking = $conn->query("SELECT id FROM rental_bookings WHERE transaction_id = $tid LIMIT 1");
            if ($booking && $booking->num_rows > 0) {
                $booking_row = $booking->fetch_assoc();
                $dep = (float) $pc['deposit_amount'];
                $has_deposit_col = $conn->query("SHOW COLUMNS FROM rental_bookings LIKE 'deposit_paid'");
                if ($has_deposit_col && $has_deposit_col->num_rows > 0) {
                    $conn->query("
                        UPDATE rental_bookings
                        SET status = 'confirmed', deposit_paid = $dep, updated_at = NOW()
                        WHERE id = {$booking_row['id']}
                    ");
                } else {
                    $conn->query("
                        UPDATE rental_bookings SET status = 'confirmed', updated_at = NOW()
                        WHERE id = {$booking_row['id']}
                    ");
                }
            }

            $escrow_table = $conn->query("SHOW TABLES LIKE 'escrow_accounts'");
            if ($escrow_table && $escrow_table->num_rows > 0) {
                $escrow_exists = $conn->query("
                    SELECT id FROM escrow_accounts
                    WHERE transaction_id = $tid AND status = 'held' LIMIT 1
                ");
                if (!$escrow_exists || $escrow_exists->num_rows === 0) {
                    $esc = $conn->prepare("
                        INSERT INTO escrow_accounts (transaction_id, user_id, amount, type, status, created_at)
                        VALUES (?, ?, ?, 'buyer_deposit', 'held', NOW())
                    ");
                    if ($esc) {
                        $esc->bind_param('iid', $tid, $uid, $amount);
                        $esc->execute();
                        $esc->close();
                    }
                }
            }

            $listing_type = $pc['listing_type'] ?? 'product';
            $auto_days = ($listing_type === 'rental') ? 14 : (($listing_type === 'product') ? 5 : 10);
            $release_date = date('Y-m-d H:i:s', strtotime("+{$auto_days} days"));

            $queue_table = $conn->query("SHOW TABLES LIKE 'escrow_release_queue'");
            if ($queue_table && $queue_table->num_rows > 0) {
                $conn->query("
                    INSERT INTO escrow_release_queue (transaction_id, scheduled_release_date, status)
                    VALUES ($tid, '$release_date', 'pending')
                    ON DUPLICATE KEY UPDATE scheduled_release_date = '$release_date', status = 'pending'
                ");
            }

            $has_release_cols = $conn->query("SHOW COLUMNS FROM transactions LIKE 'escrow_release_date'");
            if ($has_release_cols && $has_release_cols->num_rows > 0) {
                $conn->query("
                    UPDATE transactions
                    SET auto_release_days = $auto_days, escrow_release_date = '$release_date'
                    WHERE id = $tid
                ");
            }

            $seller_id = (int) $pc['seller_id'];
            $title = $conn->real_escape_string($pc['listing_title'] ?? 'your listing');
            $msg = $conn->real_escape_string('A buyer paid ' . formatMoney($amount) . ' for "' . ($pc['listing_title'] ?? 'your listing') . '". Funds are held in escrow.');
            $notif_table = $conn->query("SHOW TABLES LIKE 'notifications'");
            if ($notif_table && $notif_table->num_rows > 0) {
                $conn->query("
                    INSERT INTO notifications (user_id, title, message, link, created_at)
                    VALUES ($seller_id, 'Payment Received', '$msg', '/broker_system/user/transaction.php?id=$tid', NOW())
                ");
            }

            logTransactionAction($conn, $tid, 'deposit_payment',
                'Buyer deposit payment confirmed: ' . formatMoney($amount), $uid);

            $response['message'] = 'Payment confirmed. Escrow is active.';
            $response['escrow_active'] = true;

        } else {
            $conn->query("UPDATE transactions SET escrow_held = escrow_held + $amount WHERE id = $tid");
            $response['message'] = 'Payment confirmed';
        }

        $sync = syncTransactionPaymentState($conn, $tid);
        if ($sync) {
            $response['payment_status'] = $sync['payment_status'];
            $response['amount_paid'] = $sync['amount_paid'];
            $response['remaining_balance'] = $sync['remaining_balance'];
            $response['is_fully_paid'] = ($sync['payment_status'] === 'fully_paid');
        }

        $conn->commit();
        return $response;

    } catch (Exception $e) {
        $conn->rollback();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}


BRS/includes/seller_listing_payment.php

<?php
// includes/seller_listing_payment.php - Seller listing activation payment helpers

/**
 * Get payment state for a seller's listing activation transaction.
 *
 * @return array|null null if listing not found or not owned by seller
 */
function getSellerListingPaymentInfo($conn, $listing_id, $seller_id) {
    $listing_id = (int) $listing_id;
    $seller_id = (int) $seller_id;

    $stmt = $conn->prepare("
        SELECT l.id, l.seller_id, l.status, l.approval_status, l.price,
               l.admin_deposit_percent, l.admin_commission_percent
        FROM listings l
        WHERE l.id = ? AND l.seller_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('ii', $listing_id, $seller_id);
    $stmt->execute();
    $listing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$listing) {
        return null;
    }

    $deposit_percent = (float) ($listing['admin_deposit_percent'] ?? 30);
    $total_price = round((float) $listing['price'], 2);
    $deposit_required = round($total_price * ($deposit_percent / 100), 2);

    $txn = $conn->query("
        SELECT id, total_amount, deposit_amount, remaining_balance
        FROM transactions
        WHERE listing_id = {$listing_id}
          AND seller_id = {$seller_id}
        ORDER BY id DESC
        LIMIT 1
    ")->fetch_assoc();

    $deposit_paid = 0.0;
    $remaining_paid = 0.0;
    $has_deposit_payment = false;
    $transaction_id = $txn ? (int) $txn['id'] : 0;

    if ($transaction_id) {
        $row = $conn->query("
            SELECT
                COALESCE(SUM(CASE WHEN type = 'deposit_seller' AND status = 'confirmed' THEN 1 ELSE 0 END), 0) AS deposit_count,
                COALESCE(SUM(CASE WHEN type = 'remaining_balance' AND status = 'confirmed' THEN amount ELSE 0 END), 0) AS remaining_sum
            FROM payments
            WHERE transaction_id = {$transaction_id}
        ")->fetch_assoc();

        $has_deposit_payment = ((int) ($row['deposit_count'] ?? 0)) > 0;
        $remaining_paid = round((float) ($row['remaining_sum'] ?? 0), 2);

        if ($has_deposit_payment) {
            $deposit_paid = $txn ? round((float) $txn['deposit_amount'], 2) : $deposit_required;
        }
    }

    // Listing active implies initial deposit flow completed
    if ($listing['status'] === 'active' && $listing['approval_status'] === 'approved' && !$has_deposit_payment) {
        $has_deposit_payment = true;
        $deposit_paid = $deposit_required;
    }

    $amount_paid = round($deposit_paid + $remaining_paid, 2);
    $remaining_balance = max(0, round($total_price - $amount_paid, 2));

    if (!$has_deposit_payment) {
        $payment_status = 'pending';
    } elseif ($remaining_balance <= 0) {
        $payment_status = 'fully_paid';
    } elseif ($remaining_paid > 0) {
        $payment_status = 'partially_paid';
    } else {
        $payment_status = 'deposit_paid';
    }

    $pending_remaining_code = false;
    if ($transaction_id && $remaining_balance > 0) {
        $pending = $conn->query("
            SELECT id FROM payment_codes
            WHERE transaction_id = {$transaction_id}
              AND user_id = {$seller_id}
              AND type = 'remaining_balance'
              AND status = 'pending'
              AND expires_at > NOW()
            LIMIT 1
        ");
        $pending_remaining_code = $pending && $pending->num_rows > 0;
    }

    $is_owner = true;
    $is_active = ($listing['status'] === 'active' && $listing['approval_status'] === 'approved');
    $can_pay_remaining = $is_owner
        && $is_active
        && $has_deposit_payment
        && $remaining_balance > 0
        && $payment_status !== 'fully_paid';

    return [
        'listing_id' => $listing_id,
        'transaction_id' => $transaction_id,
        'total_price' => $total_price,
        'deposit_required' => $deposit_required,
        'deposit_paid' => $deposit_paid,
        'remaining_paid' => $remaining_paid,
        'amount_paid' => $amount_paid,
        'remaining_balance' => $remaining_balance,
        'payment_status' => $payment_status,
        'has_deposit_payment' => $has_deposit_payment,
        'is_active' => $is_active,
        'can_pay_remaining' => $can_pay_remaining,
        'pending_remaining_code' => $pending_remaining_code,
        'deposit_percent' => $deposit_percent,
    ];
}

/**
 * Persist seller_payment_status on listings when column exists.
 */
function updateListingSellerPaymentStatus($conn, $listing_id, $status) {
    $allowed = ['pending', 'deposit_paid', 'partially_paid', 'fully_paid'];
    if (!in_array($status, $allowed, true)) {
        return;
    }

    $col = $conn->query("SHOW COLUMNS FROM listings LIKE 'seller_payment_status'");
    if (!$col || $col->num_rows === 0) {
        return;
    }

    $listing_id = (int) $listing_id;
    $stmt = $conn->prepare("UPDATE listings SET seller_payment_status = ? WHERE id = ?");
    $stmt->bind_param('si', $status, $listing_id);
    $stmt->execute();
    $stmt->close();
}


BRS/includes/telebirr_simulation.php

<?php
// includes/telebirr_simulation.php - Telebirr simulation transfer service

/**
 * Get the path to the shared Telebirr simulator JSON storage.
 * This is the same storage used by the desktop Telebirr simulator app.
 *
 * @return string
 */
function getTelebirrJsonFilePath() {
    return __DIR__ . '/../telebirr_users.json';
}

/**
 * Load Telebirr simulator data from JSON storage.
 *
 * @return array
 */
function loadTelebirrData() {
    $path = getTelebirrJsonFilePath();
    if (!file_exists($path)) {
        $data = [
            'users' => [],
            'transactions' => []
        ];
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        return $data;
    }

    $json = file_get_contents($path);
    $data = json_decode($json, true);
    if (!is_array($data)) {
        $data = [
            'users' => [],
            'transactions' => []
        ];
    }

    if (!isset($data['users']) || !is_array($data['users'])) {
        $data['users'] = [];
    }
    if (!isset($data['transactions']) || !is_array($data['transactions'])) {
        $data['transactions'] = [];
    }

    return $data;
}

/**
 * Save Telebirr simulator data back to JSON storage.
 *
 * @param array $data
 * @return bool
 */
function saveTelebirrData(array $data) {
    $path = getTelebirrJsonFilePath();
    return file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
}

/**
 * Normalize an Ethiopian Telebirr phone number to +251XXXXXXXXX format.
 *
 * @param string $phone
 * @return string
 */
function normalizeTelebirrPhone($phone) {
    $phone = preg_replace('/[^0-9+]/', '', trim($phone));
    if (strpos($phone, '+251') === 0 && strlen($phone) === 13) {
        return $phone;
    }
    if (strpos($phone, '0') === 0 && strlen($phone) === 10) {
        return '+251' . substr($phone, 1);
    }
    if (strlen($phone) === 9) {
        return '+251' . $phone;
    }
    return $phone;
}

/**
 * Get the platform Telebirr sender phone used for withdrawal payouts.
 *
 * @return string
 */
function getPlatformTelebirrPhone() {
    return '+251900000000';
}

/**
 * Ensure a Telebirr account exists in the simulator data.
 * If the account does not exist, create a placeholder account.
 *
 * @param string $phone
 * @param string $fullName
 * @param string $pin
 * @param float $initialBalance
 * @return array
 */
function ensureTelebirrAccount($phone, $fullName = 'Telebirr User', $pin = '1234', $initialBalance = 0.0) {
    $phone = normalizeTelebirrPhone($phone);
    $data = loadTelebirrData();

    if (isset($data['users'][$phone])) {
        return $data['users'][$phone];
    }

    $data['users'][$phone] = [
        'id' => count($data['users']) + 1,
        'phone' => $phone,
        'full_name' => $fullName,
        'pin' => $pin,
        'balance' => (float) $initialBalance,
        'level' => 1,
        'endekise' => 0.0,
        'rewards' => 0.0,
        'created_at' => date('c')
    ];

    saveTelebirrData($data);
    return $data['users'][$phone];
}

/**
 * Generate a unique Telebirr transfer reference.
 *
 * @return string
 */
function generateTelebirrTransferReference() {
    return 'TBR-' . time() . '-' . bin2hex(random_bytes(4));
}

/**
 * Perform a Telebirr transfer from sender to receiver inside the shared simulator.
 *
 * @param string $senderPhone
 * @param string $receiverPhone
 * @param float $amount
 * @param string|null $description
 * @param string|null $reference
 * @param string|null &$error
 * @return array|false
 */
function performTelebirrTransfer($senderPhone, $receiverPhone, $amount, $description = null, $reference = null, &$error = null) {
    $senderPhone = normalizeTelebirrPhone($senderPhone);
    $receiverPhone = normalizeTelebirrPhone($receiverPhone);
    $amount = round((float) $amount, 2);
    $description = trim($description ?: 'Telebirr withdrawal transfer');
    $reference = $reference ?: generateTelebirrTransferReference();

    if ($amount <= 0) {
        $error = 'Transfer amount must be greater than zero';
        return false;
    }

    $data = loadTelebirrData();
    if (!isset($data['users'][$senderPhone])) {
        ensureTelebirrAccount($senderPhone, 'Platform Telebirr Account', '1234', 10000000.00);
        $data = loadTelebirrData();
    }
    if (!isset($data['users'][$receiverPhone])) {
        ensureTelebirrAccount($receiverPhone, 'Telebirr Recipient', '1234', 0.00);
        $data = loadTelebirrData();
    }

    if ($data['users'][$senderPhone]['balance'] < $amount) {
        $error = 'Platform Telebirr account has insufficient balance';
        return false;
    }

    $data['users'][$senderPhone]['balance'] = round($data['users'][$senderPhone]['balance'] - $amount, 2);
    $data['users'][$receiverPhone]['balance'] = round($data['users'][$receiverPhone]['balance'] + $amount, 2);

    $transactionDate = date('d M Y H:i');
    array_unshift($data['transactions'], [
        'user_phone' => $senderPhone,
        'type' => 'transfer_out',
        'amount' => (float) $amount,
        'fee' => 0.0,
        'description' => $description,
        'reference' => $reference,
        'date' => $transactionDate,
        'status' => 'completed'
    ]);

    array_unshift($data['transactions'], [
        'user_phone' => $receiverPhone,
        'type' => 'transfer_in',
        'amount' => (float) $amount,
        'fee' => 0.0,
        'description' => $description,
        'reference' => $reference,
        'date' => $transactionDate,
        'status' => 'completed'
    ]);

    if (!saveTelebirrData($data)) {
        $error = 'Failed to save Telebirr simulation state';
        return false;
    }

    return [
        'reference' => $reference,
        'sender_phone' => $senderPhone,
        'receiver_phone' => $receiverPhone,
        'amount' => $amount,
        'description' => $description,
        'status' => 'success',
        'transferred_at' => date('Y-m-d H:i:s')
    ];
}


BRS/includes/transaction_workflow.php

<?php
// includes/transaction_workflow.php - Unified payment & escrow workflow

require_once __DIR__ . '/functions.php';

function txnHasColumn($conn, $column) {
    static $cache = [];
    $key = 'transactions.' . $column;
    if (!isset($cache[$key])) {
        $col = $conn->real_escape_string($column);
        $r = $conn->query("SHOW COLUMNS FROM transactions LIKE '$col'");
        $cache[$key] = ($r && $r->num_rows > 0);
    }
    return $cache[$key];
}

/**
 * Compute payment totals from confirmed payments.
 */
function computeTransactionPaymentTotals($conn, $transaction_id) {
    $transaction_id = (int) $transaction_id;
    $txn = $conn->query("
        SELECT total_amount, deposit_amount, commission_amount, remaining_balance
        FROM transactions WHERE id = $transaction_id
    ")->fetch_assoc();

    if (!$txn) {
        return null;
    }

    $total = round((float) $txn['total_amount'], 2);
    $deposit_required = round((float) $txn['deposit_amount'], 2);

    $buyer_deposit = $conn->query("
        SELECT id FROM payments
        WHERE transaction_id = $transaction_id
          AND type = 'deposit_buyer' AND status = 'confirmed'
        LIMIT 1
    ");
    $has_deposit = ($buyer_deposit && $buyer_deposit->num_rows > 0);

    $full_payment = $conn->query("
        SELECT COALESCE(SUM(amount), 0) AS s FROM payments
        WHERE transaction_id = $transaction_id
          AND type = 'full_payment' AND status = 'confirmed'
    ")->fetch_assoc();

    $remaining_paid = (float) $conn->query("
        SELECT COALESCE(SUM(amount), 0) AS s FROM payments
        WHERE transaction_id = $transaction_id
          AND type = 'remaining_balance' AND status = 'confirmed'
    ")->fetch_assoc()['s'];

    $amount_paid = 0.0;
    if ($has_deposit) {
        $amount_paid = $deposit_required;
    }
    if ((float) $full_payment['s'] > 0) {
        $amount_paid = $total;
    } else {
        $amount_paid += $remaining_paid;
    }

    $amount_paid = min($total, round($amount_paid, 2));
    $remaining = max(0, round($total - $amount_paid, 2));

    if ($amount_paid <= 0) {
        $payment_status = 'pending';
    } elseif ($remaining <= 0) {
        $payment_status = 'fully_paid';
    } elseif ($has_deposit || $amount_paid > 0) {
        $payment_status = ($remaining_paid > 0) ? 'partially_paid' : 'deposit_paid';
    } else {
        $payment_status = 'pending';
    }

    return [
        'total_amount' => $total,
        'deposit_amount' => $deposit_required,
        'amount_paid' => $amount_paid,
        'remaining_balance' => $remaining,
        'payment_status' => $payment_status,
        'has_buyer_deposit' => $has_deposit,
    ];
}

/**
 * Sync amount_paid, remaining_balance, payment_status on transactions row.
 */
function syncTransactionPaymentState($conn, $transaction_id) {
    $transaction_id = (int) $transaction_id;
    $calc = computeTransactionPaymentTotals($conn, $transaction_id);
    if (!$calc) {
        return null;
    }

    $escrow_held = (float) ($conn->query("
        SELECT COALESCE(SUM(amount), 0) AS s FROM payments
        WHERE transaction_id = $transaction_id AND status = 'confirmed'
          AND type IN ('deposit_buyer', 'remaining_balance', 'full_payment', 'deposit_seller')
    ")->fetch_assoc()['s'] ?? 0);

    $parts = [
        "remaining_balance = {$calc['remaining_balance']}",
        "escrow_held = $escrow_held",
    ];

    if (txnHasColumn($conn, 'amount_paid')) {
        $parts[] = "amount_paid = {$calc['amount_paid']}";
    }
    if (txnHasColumn($conn, 'payment_status')) {
        $ps = $conn->real_escape_string($calc['payment_status']);
        $parts[] = "payment_status = '$ps'";
    }

    $txn = $conn->query("SELECT status, funds_status, escrow_status FROM transactions WHERE id = $transaction_id")->fetch_assoc();
    if ($calc['payment_status'] !== 'pending' && $txn) {
        if (txnHasColumn($conn, 'funds_status') && !in_array($txn['funds_status'] ?? '', ['released', 'completed', 'disputed', 'cancelled'], true)) {
            $parts[] = "funds_status = 'held_in_escrow'";
        }
        if (!in_array($txn['status'] ?? '', ['completed', 'disputed', 'cancelled'], true) && $calc['has_buyer_deposit']) {
            if (txnHasColumn($conn, 'escrow_status')) {
                $parts[] = "escrow_status = 'active'";
            }
            if ($txn['status'] === 'awaiting_buyer_deposit' || $txn['status'] === 'pending_deposit') {
                $parts[] = "status = 'in_progress'";
            }
        }
    }

    $conn->query("UPDATE transactions SET " . implode(', ', $parts) . ", updated_at = NOW() WHERE id = $transaction_id");

    return $calc;
}

function logTransactionAction($conn, $transaction_id, $action_type, $description, $user_id = null, $amount = null) {
    if (!function_exists('addTransactionTimeline')) {
        require_once __DIR__ . '/escrow_functions.php';
    }
    if (function_exists('addTransactionTimeline')) {
        $table = $conn->query("SHOW TABLES LIKE 'transaction_timeline'");
        if ($table && $table->num_rows > 0) {
            addTransactionTimeline($conn, $transaction_id, $action_type, $description, $user_id);
            return;
        }
    }
}

function markSellerConfirmed($conn, $transaction_id, $seller_id, $notes = '') {
    $transaction_id = (int) $transaction_id;
    $seller_id = (int) $seller_id;

    $txn = $conn->query("
        SELECT * FROM transactions
        WHERE id = $transaction_id AND seller_id = $seller_id
    ")->fetch_assoc();

    if (!$txn) {
        return ['success' => false, 'error' => 'Unauthorized or transaction not found'];
    }

    if (($txn['funds_status'] ?? '') === 'disputed' || ($txn['status'] ?? '') === 'disputed') {
        return ['success' => false, 'error' => 'Cannot confirm while dispute is open'];
    }

    $sets = ["delivery_status = 'delivered'", "delivered_at = NOW()", "updated_at = NOW()"];
    if (txnHasColumn($conn, 'seller_confirmed')) {
        $sets[] = 'seller_confirmed = 1';
        $sets[] = 'seller_confirmed_at = NOW()';
    }
    if (txnHasColumn($conn, 'funds_status')) {
        $sets[] = "funds_status = 'seller_confirmed'";
    }

    $conn->query("UPDATE transactions SET " . implode(', ', $sets) . " WHERE id = $transaction_id");

    logTransactionAction($conn, $transaction_id, 'seller_confirmed',
        'Seller confirmed delivery' . ($notes ? ': ' . $notes : ''), $seller_id);

    tryAutoReleaseFunds($conn, $transaction_id, $seller_id);

    return ['success' => true, 'message' => 'Delivery confirmed. Waiting for buyer confirmation.'];
}

function markBuyerConfirmed($conn, $transaction_id, $buyer_id, $notes = '') {
    $transaction_id = (int) $transaction_id;
    $buyer_id = (int) $buyer_id;

    $txn = $conn->query("
        SELECT * FROM transactions
        WHERE id = $transaction_id AND buyer_id = $buyer_id
    ")->fetch_assoc();

    if (!$txn) {
        return ['success' => false, 'error' => 'Unauthorized or transaction not found'];
    }

    if (($txn['funds_status'] ?? '') === 'disputed' || ($txn['status'] ?? '') === 'disputed') {
        return ['success' => false, 'error' => 'Cannot confirm while dispute is open'];
    }

    $seller_ok = (int) ($txn['seller_confirmed'] ?? 0) === 1
        || ($txn['delivery_status'] ?? '') === 'delivered';

    if (!$seller_ok) {
        return ['success' => false, 'error' => 'Seller has not confirmed delivery yet'];
    }

    $sets = ['updated_at = NOW()'];
    if (txnHasColumn($conn, 'buyer_confirmed')) {
        $sets[] = 'buyer_confirmed = 1';
        $sets[] = 'buyer_confirmed_at = NOW()';
    }
    if (txnHasColumn($conn, 'funds_status')) {
        $sets[] = "funds_status = 'buyer_confirmed'";
    }

    $conn->query("UPDATE transactions SET " . implode(', ', $sets) . " WHERE id = $transaction_id");

    logTransactionAction($conn, $transaction_id, 'buyer_confirmed',
        'Buyer confirmed receipt' . ($notes ? ': ' . $notes : ''), $buyer_id);

    return tryAutoReleaseFunds($conn, $transaction_id, $buyer_id);
}

function tryAutoReleaseFunds($conn, $transaction_id, $performed_by) {
    $transaction_id = (int) $transaction_id;
    $txn = $conn->query("SELECT * FROM transactions WHERE id = $transaction_id")->fetch_assoc();

    if (!$txn || ($txn['status'] ?? '') === 'completed') {
        return ['success' => true, 'already_completed' => true];
    }

    if (($txn['funds_status'] ?? '') === 'disputed' || ($txn['status'] ?? '') === 'disputed') {
        return ['success' => false, 'error' => 'Funds locked due to dispute'];
    }

    $seller_ok = (int) ($txn['seller_confirmed'] ?? 0) === 1
        || ($txn['delivery_status'] ?? '') === 'delivered';
    $buyer_ok = (int) ($txn['buyer_confirmed'] ?? 0) === 1;

    if (!$seller_ok || !$buyer_ok) {
        if (txnHasColumn($conn, 'funds_status') && $seller_ok && !$buyer_ok) {
            $conn->query("UPDATE transactions SET funds_status = 'seller_confirmed' WHERE id = $transaction_id");
        }
        return [
            'success' => true,
            'released' => false,
            'message' => 'Waiting for both parties to confirm',
        ];
    }

    if (txnHasColumn($conn, 'funds_status')) {
        $conn->query("UPDATE transactions SET funds_status = 'ready_for_release' WHERE id = $transaction_id");
    }

    if (!function_exists('releaseEscrowPayment')) {
        require_once __DIR__ . '/escrow_functions.php';
    }

    $result = releaseEscrowPayment($conn, $transaction_id, $performed_by, 'dual_confirm', 'Automatic release after buyer and seller confirmation');

    if ($result['success'] && txnHasColumn($conn, 'funds_status')) {
        $conn->query("
            UPDATE transactions
            SET funds_status = 'released', funds_released_at = NOW()
            WHERE id = $transaction_id
        ");
    }

    return $result;
}

function openTransactionDispute($conn, $transaction_id, $user_id, $reason) {
    $transaction_id = (int) $transaction_id;
    $user_id = (int) $user_id;
    $reason = trim($reason);

    if ($reason === '') {
        return ['success' => false, 'error' => 'Dispute reason is required'];
    }

    $txn = $conn->query("
        SELECT id FROM transactions
        WHERE id = $transaction_id AND (buyer_id = $user_id OR seller_id = $user_id)
    ")->fetch_assoc();

    if (!$txn) {
        return ['success' => false, 'error' => 'Unauthorized'];
    }

    $stmt = $conn->prepare("
        INSERT INTO disputes (transaction_id, raised_by, reason, status, created_at)
        VALUES (?, ?, ?, 'open', NOW())
    ");
    $stmt->bind_param('iis', $transaction_id, $user_id, $reason);
    $stmt->execute();
    $stmt->close();

    $sets = ["status = 'disputed'", "updated_at = NOW()"];
    if (txnHasColumn($conn, 'funds_status')) {
        $sets[] = "funds_status = 'disputed'";
    }
    if (txnHasColumn($conn, 'escrow_status')) {
        $sets[] = "escrow_status = 'disputed'";
    }

    $conn->query("UPDATE transactions SET " . implode(', ', $sets) . " WHERE id = $transaction_id");

    logTransactionAction($conn, $transaction_id, 'dispute_opened', 'Dispute opened: ' . $reason, $user_id);

    return ['success' => true, 'message' => 'Dispute submitted for admin review'];
}

function getTransactionWorkflowView($conn, $transaction_id) {
    $transaction_id = (int) $transaction_id;
    syncTransactionPaymentState($conn, $transaction_id);

    $txn = $conn->query("SELECT * FROM transactions WHERE id = $transaction_id")->fetch_assoc();
    if (!$txn) {
        return null;
    }

    $calc = computeTransactionPaymentTotals($conn, $transaction_id);

    return array_merge($txn, [
        'amount_paid' => $calc['amount_paid'],
        'remaining_balance' => $calc['remaining_balance'],
        'payment_status' => $calc['payment_status'],
        'total_amount' => $calc['total_amount'],
    ]);
}

/**
 * Create payment code for buyer remaining balance on a transaction.
 */
function initiateBuyerRemainingPayment($conn, $transaction_id, $buyer_id) {
    $transaction_id = (int) $transaction_id;
    $buyer_id = (int) $buyer_id;

    $calc = syncTransactionPaymentState($conn, $transaction_id);
    if (!$calc || $calc['remaining_balance'] <= 0) {
        return ['success' => false, 'error' => 'No remaining balance to pay'];
    }

    $txn = $conn->query("
        SELECT id FROM transactions
        WHERE id = $transaction_id AND buyer_id = $buyer_id
    ")->fetch_assoc();

    if (!$txn) {
        return ['success' => false, 'error' => 'Unauthorized'];
    }

    $pending = $conn->query("
        SELECT code FROM payment_codes
        WHERE transaction_id = $transaction_id
          AND user_id = $buyer_id
          AND type = 'remaining_balance'
          AND status = 'pending'
          AND expires_at > NOW()
        ORDER BY id DESC LIMIT 1
    ");

    if ($pending && $pending->num_rows > 0) {
        $row = $pending->fetch_assoc();
        return [
            'success' => true,
            'payment_code' => $row['code'],
            'amount' => $calc['remaining_balance'],
            'pay_url' => '/broker_system/user/pay_rent.php?transaction_id=' . $transaction_id . '&pay=remaining',
        ];
    }

    do {
        $code = str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT);
        $chk = $conn->prepare('SELECT id FROM payment_codes WHERE code = ?');
        $chk->bind_param('s', $code);
        $chk->execute();
        $exists = $chk->get_result()->num_rows > 0;
        $chk->close();
    } while ($exists);

    $amount = $calc['remaining_balance'];
    $stmt = $conn->prepare("
        INSERT INTO payment_codes (code, transaction_id, amount, user_id, type, expires_at, status, created_at)
        VALUES (?, ?, ?, ?, 'remaining_balance', DATE_ADD(NOW(), INTERVAL 30 MINUTE), 'pending', NOW())
    ");
    $stmt->bind_param('sidi', $code, $transaction_id, $amount, $buyer_id);
    $stmt->execute();
    $stmt->close();

    return [
        'success' => true,
        'payment_code' => $code,
        'amount' => $amount,
        'pay_url' => '/broker_system/user/pay_rent.php?transaction_id=' . $transaction_id . '&pay=remaining',
    ];
}


BRS/includes/upload.php

<?php
// includes/upload.php - Image upload handling (FIXED)

function uploadImage($file, $targetDir = '../uploads/listings/') {
    // Create directory if not exists
    if (!file_exists($targetDir)) {
        mkdir($targetDir, 0777, true);
    }
    
    $fileName = time() . '_' . uniqid() . '_' . basename($file['name']);
    $targetFile = $targetDir . $fileName;
    $imageFileType = strtolower(pathinfo($targetFile, PATHINFO_EXTENSION));
    
    // Check if image file is actual image
    $check = getimagesize($file['tmp_name']);
    if ($check === false) {
        return ['success' => false, 'error' => 'File is not an image'];
    }
    
    // Check file size (5MB max)
    if ($file['size'] > 5000000) {
        return ['success' => false, 'error' => 'File is too large (max 5MB)'];
    }
    
    // Allow certain file formats
    $allowedFormats = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (!in_array($imageFileType, $allowedFormats)) {
        return ['success' => false, 'error' => 'Only JPG, JPEG, PNG, GIF & WEBP files are allowed'];
    }
    
    // Upload file
    if (move_uploaded_file($file['tmp_name'], $targetFile)) {
        return ['success' => true, 'filename' => $fileName, 'path' => $targetFile];
    } else {
        return ['success' => false, 'error' => 'Failed to upload image'];
    }
}

function deleteImage($filename, $targetDir = '../uploads/listings/') {
    $filePath = $targetDir . $filename;
    if (file_exists($filePath)) {
        unlink($filePath);
        return true;
    }
    return false;
}
?>

BRS/includes/validation.php

<?php
// includes/validation.php - Complete Validation & Sanitization System

/**
 * ============================================
 * SANITIZATION FUNCTIONS
 * ============================================
 */

/**
 * Sanitize string input (HTML escape)
 */
function sanitizeString($input) {
    if ($input === null) return '';
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Sanitize integer
 */
function sanitizeInt($input, $default = 0) {
    $filtered = filter_var($input, FILTER_VALIDATE_INT);
    return $filtered !== false ? $filtered : $default;
}

/**
 * Sanitize float/decimal
 */
function sanitizeFloat($input, $default = 0.00) {
    $filtered = filter_var($input, FILTER_VALIDATE_FLOAT);
    return $filtered !== false ? $filtered : $default;
}

/**
 * Sanitize email
 */
function sanitizeEmail($email) {
    $email = trim($email);
    return filter_var($email, FILTER_SANITIZE_EMAIL);
}

/**
 * Sanitize URL
 */
function sanitizeUrl($url) {
    $url = trim($url);
    return filter_var($url, FILTER_SANITIZE_URL);
}

/**
 * Sanitize phone number (keep only digits and +)
 */
function sanitizePhone($phone) {
    return preg_replace('/[^0-9+]/', '', $phone);
}

/**
 * Sanitize array recursively
 */
function sanitizeArray($array) {
    if (!is_array($array)) {
        return sanitizeString($array);
    }
    
    $result = [];
    foreach ($array as $key => $value) {
        $result[sanitizeString($key)] = is_array($value) ? sanitizeArray($value) : sanitizeString($value);
    }
    return $result;
}

/**
 * Sanitize for database input (SQL safe)
 */
function sanitizeForDb($conn, $input) {
    return $conn->real_escape_string(trim($input));
}

/**
 * Sanitize filename (remove path traversal)
 */
function sanitizeFilename($filename) {
    // Remove any path information
    $filename = basename($filename);
    // Remove special characters
    $filename = preg_replace('/[^a-zA-Z0-9._-]/', '', $filename);
    return $filename;
}

/**
 * ============================================
 * VALIDATION FUNCTIONS
 * ============================================
 */

/**
 * Validate email format
 */
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validate phone number (Ethiopian format)
 */
function validatePhone($phone) {
    $phone = preg_replace('/[^0-9+]/', '', $phone);
    
    $patterns = [
        '/^\+251[0-9]{9}$/',      // +251XXXXXXXXX
        '/^0[0-9]{9}$/',           // 0XXXXXXXXX  
        '/^[0-9]{10}$/'            // XXXXXXXXXX
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $phone)) {
            return true;
        }
    }
    return false;
}

/**
 * Validate password strength
 */
function validatePasswordStrength($password) {
    $errors = [];
    
    if (strlen($password) < 6) {
        $errors[] = "Password must be at least 6 characters";
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = "Password must contain at least one uppercase letter";
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = "Password must contain at least one lowercase letter";
    }
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = "Password must contain at least one number";
    }
    
    return $errors;
}

/**
 * Validate amount (positive, max 2 decimals)
 */
function validateAmount($amount) {
    if (!is_numeric($amount) || $amount <= 0) {
        return false;
    }
    // Check for more than 2 decimal places
    if (preg_match('/\.[0-9]{3,}$/', (string)$amount)) {
        return false;
    }
    return true;
}

/**
 * Validate required fields
 */
function validateRequired($data, $fields) {
    $errors = [];
    foreach ($fields as $field) {
        if (empty($data[$field])) {
            $errors[] = ucfirst(str_replace('_', ' ', $field)) . " is required";
        }
    }
    return $errors;
}

/**
 * Validate string length
 */
function validateLength($input, $min, $max) {
    $length = strlen(trim($input));
    return $length >= $min && $length <= $max;
}

/**
 * Validate date format
 */
function validateDate($date, $format = 'Y-m-d') {
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

/**
 * Validate URL
 */
function validateUrl($url) {
    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}

/**
 * Validate listing type
 */
function validateListingType($type) {
    $valid = ['product', 'job', 'rental'];
    return in_array($type, $valid);
}

/**
 * Validate transaction status
 */
function validateTransactionStatus($status) {
    $valid = ['pending_deposit', 'awaiting_buyer_deposit', 'awaiting_seller_deposit',
              'deposits_complete', 'in_progress', 'completed', 'disputed', 'cancelled'];
    return in_array($status, $valid);
}

/**
 * Validate Ethiopian TIN (Tax Identification Number)
 */
function validateTIN($tin) {
    return preg_match('/^[0-9]{10,15}$/', $tin);
}

/**
 * Validate bank account number
 */
function validateBankAccount($account) {
    $account = preg_replace('/[^0-9]/', '', $account);
    return strlen($account) >= 8 && strlen($account) <= 20;
}

/**
 * Validate file upload
 */
function validateFileUpload($file, $maxSize = 5242880, $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp']) {
    $errors = [];
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = "File upload failed (Error code: " . $file['error'] . ")";
        return $errors;
    }
    
    if ($file['size'] > $maxSize) {
        $errors[] = "File size exceeds " . ($maxSize / 1048576) . "MB limit";
    }
    
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mimeType, $allowedTypes)) {
        $errors[] = "Invalid file type. Allowed: JPG, PNG, GIF, WEBP";
    }
    
    return $errors;
}

/**
 * Validate CSRF token
 */
function validateCSRF($token) {
    if (!isset($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Generate CSRF token
 */
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * ============================================
 * DATABASE VALIDATION
 * ============================================
 */

/**
 * Check if email exists in database
 */
function emailExists($conn, $email, $excludeId = null) {
    $sql = "SELECT id FROM users WHERE email = ?";
    $params = [$email];
    $types = "s";
    
    if ($excludeId) {
        $sql .= " AND id != ?";
        $params[] = $excludeId;
        $types .= "i";
    }
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->num_rows > 0;
}

/**
 * Check if listing belongs to user
 */
function validateListingOwnership($conn, $listingId, $userId) {
    $stmt = $conn->prepare("SELECT id FROM listings WHERE id = ? AND seller_id = ?");
    $stmt->bind_param("ii", $listingId, $userId);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
}

/**
 * Check if transaction belongs to user
 */
function validateTransactionAccess($conn, $transactionId, $userId) {
    $stmt = $conn->prepare("SELECT id FROM transactions WHERE id = ? AND (buyer_id = ? OR seller_id = ?)");
    $stmt->bind_param("iii", $transactionId, $userId, $userId);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
}

/**
 * ============================================
 * INPUT PROCESSING (Combined)
 * ============================================
 */

/**
 * Process and validate a complete form input
 */
function processFormInput($data, $rules) {
    $errors = [];
    $sanitized = [];
    
    foreach ($rules as $field => $rule) {
        $value = $data[$field] ?? '';
        
        // Sanitize based on type
        switch ($rule['type'] ?? 'string') {
            case 'int':
                $sanitized[$field] = sanitizeInt($value);
                break;
            case 'float':
                $sanitized[$field] = sanitizeFloat($value);
                break;
            case 'email':
                $sanitized[$field] = sanitizeEmail($value);
                break;
            case 'phone':
                $sanitized[$field] = sanitizePhone($value);
                break;
            default:
                $sanitized[$field] = sanitizeString($value);
        }
        
        // Validate required
        if (($rule['required'] ?? false) && empty($sanitized[$field])) {
            $errors[] = $rule['label'] . " is required";
        }
        
        // Validate email format
        if (($rule['type'] ?? '') == 'email' && !empty($sanitized[$field]) && !validateEmail($sanitized[$field])) {
            $errors[] = "Please enter a valid email address";
        }
        
        // Validate min length
        if (isset($rule['min']) && strlen($sanitized[$field]) < $rule['min']) {
            $errors[] = $rule['label'] . " must be at least " . $rule['min'] . " characters";
        }
        
        // Validate max length
        if (isset($rule['max']) && strlen($sanitized[$field]) > $rule['max']) {
            $errors[] = $rule['label'] . " must not exceed " . $rule['max'] . " characters";
        }
        
        // Validate in array
        if (isset($rule['in']) && !in_array($sanitized[$field], $rule['in'])) {
            $errors[] = "Please select a valid option for " . $rule['label'];
        }
    }
    
    return [
        'success' => empty($errors),
        'errors' => $errors,
        'data' => $sanitized
    ];
}

/**
 * ============================================
 * HELPER FUNCTIONS
 * ============================================
 */

/**
 * Get validation error summary as HTML
 */
function getValidationErrorsHTML($errors) {
    if (empty($errors)) return '';
    
    $html = '<div class="alert alert-error" style="background: #fee2e2; color: #dc2626; padding: 12px; border-radius: 12px; margin-bottom: 20px; border-left: 4px solid #dc2626;">';
    $html .= '<i class="fas fa-exclamation-triangle"></i> <strong>Please fix the following errors:</strong><ul style="margin-top: 8px; margin-left: 20px;">';
    foreach ($errors as $error) {
        $html .= '<li>' . htmlspecialchars($error) . '</li>';
    }
    $html .= '</ul></div>';
    
    return $html;
}

/**
 * Log validation error (for debugging)
 */
function logValidationError($message, $data = []) {
    $log = date('Y-m-d H:i:s') . " - " . $message;
    if (!empty($data)) {
        $log .= " - Data: " . json_encode($data);
    }
    error_log($log . PHP_EOL, 3, __DIR__ . '/../logs/validation.log');
}

/**
 * Quick validate integer range
 */
function validateIntRange($value, $min, $max) {
    $value = sanitizeInt($value);
    return $value >= $min && $value <= $max;
}

/**
 * Quick validate string (no special chars)
 */
function validateAlphaNumeric($string, $allowSpaces = true) {
    $pattern = $allowSpaces ? '/^[a-zA-Z0-9\s]+$/' : '/^[a-zA-Z0-9]+$/';
    return preg_match($pattern, $string);
}

/**
 * Validate JSON string
 */
function validateJSON($string) {
    if (empty($string)) return true;
    json_decode($string);
    return json_last_error() === JSON_ERROR_NONE;
}
?>

