<?php
// ============================================
// FILE: cron/process_reservations.php
// Description: Cron job to process expired reservations
// Run daily via: 0 0 * * * php /path/to/cron/process_reservations.php
// ============================================

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/AvailabilityManager.php';

$conn = getDbConnection();
$availabilityManager = new AvailabilityManager($conn);

// Process expired reservations (check-out date passed)
$released = $availabilityManager->processExpiredReservations();

// Also process auto-release queue from escrow
if (function_exists('processAutoReleaseQueue')) {
    $escrow_released = processAutoReleaseQueue($conn);
} else {
    $escrow_released = 0;
}

echo "[" . date('Y-m-d H:i:s') . "] Processed $released expired reservations, $escrow_released escrow releases\n";

$conn->close();
?>