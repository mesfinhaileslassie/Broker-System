<?php
// config/config.php

// System settings (can be overridden from database)
$SYSTEM_CONFIG = [
    'site_name' => 'Ethio Brokerplace',
    'deposit_percent' => 30,      // 30% deposit from both parties
    'commission_percent' => 15,   // 15% system commission
    'currency' => 'ETB',
    'escrow_days' => 14,          // Days funds held in escrow
    'telebirr_simulation' => true
];

function getSetting($key, $default = null) {
    global $SYSTEM_CONFIG;
    
    // Try to get from database first
    $conn = getDbConnection();
    $result = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = '$key'");
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $conn->close();
        return $row['setting_value'];
    }
    
    $conn->close();
    return $SYSTEM_CONFIG[$key] ?? $default;
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