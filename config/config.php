<?php
// config/config.php - Configuration file (no function declarations)

// System settings (default values - can be overridden from database)
$SYSTEM_CONFIG = [
    'site_name' => 'Ethio Brokerplace',
    'deposit_percent' => 30,
    'deposit_percent_product' => 30,
    'deposit_percent_job' => 30,
    'deposit_percent_rental' => 30,
    'commission_percent' => 15,
    'commission_percent_product' => 15,
    'commission_percent_job' => 15,
    'commission_percent_rental' => 15,
    'currency' => 'ETB',
    'escrow_days' => 14,
    'min_withdrawal' => 100,
    'max_withdrawal' => 100000,
    'telebirr_simulation' => true
];

// Helper function to get config values (not database settings)
function getConfig($key, $default = null) {
    global $SYSTEM_CONFIG;
    return $SYSTEM_CONFIG[$key] ?? $default;
}
?>