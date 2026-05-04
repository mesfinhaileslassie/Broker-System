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