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