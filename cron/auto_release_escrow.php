<?php
// ============================================
// FILE: cron/auto_release_escrow.php
// ============================================
// Run this script daily via cron job

require_once '../config/database.php';
require_once '../includes/escrow_functions.php';

$conn = getDbConnection();
$released = processAutoReleaseQueue($conn);

echo date('Y-m-d H:i:s') . " - Released $released payments\n";

// Log to file
file_put_contents(__DIR__ . '/escrow_release.log', 
    date('Y-m-d H:i:s') . " - Released $released payments\n", 
    FILE_APPEND);

$conn->close();
?>