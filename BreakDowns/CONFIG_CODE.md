BRS/config/config.php

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

BRS/config/database.local.php.example

<?php
// Copy this file to database.local.php (same folder) and set your XAMPP MySQL credentials.

$dbHost = 'localhost';
$dbUser = 'root';
$dbPass = 'your_mysql_password_here';
$dbName = 'brokersystem';


BRS/config/database.php

<?php
// config/database.php

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'brokersystem');  // Changed from 'broker_system' to 'brokersystem'

function getDbConnection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    
    return $conn;
}

function safeQuery($sql, $params = [], $types = "") {
    $conn = getDbConnection();
    $stmt = $conn->prepare($sql);
    
    if ($params && $types) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $conn->close();
    
    return $result;
}

