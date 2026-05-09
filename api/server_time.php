<?php
// api/server_time.php - Single source of truth for time

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

require_once '../config/database.php';

date_default_timezone_set('Africa/Addis_Ababa');

$conn = getDbConnection();
$conn->query("SET time_zone = '+03:00'");

// Get MySQL time as the ultimate source
$mysql_time_result = $conn->query("SELECT NOW() as mysql_time, UNIX_TIMESTAMP() as mysql_timestamp");
$mysql_time = $mysql_time_result->fetch_assoc();

$response = [
    'success' => true,
    'mysql_timestamp' => intval($mysql_time['mysql_timestamp']) * 1000, // Convert to milliseconds
    'mysql_time' => $mysql_time['mysql_time'],
    'php_timestamp' => time() * 1000,
    'timezone' => 'Africa/Addis_Ababa',
    'utc_offset' => 10800000 // 3 hours in milliseconds
];

$conn->close();
echo json_encode($response);
?>