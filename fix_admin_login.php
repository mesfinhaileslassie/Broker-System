<?php
// fix_admin_login.php - Run this to fix admin login
// DELETE AFTER USE!

require_once 'config/database.php';

$conn = getDbConnection();

// Check if admin exists
$check = $conn->query("SELECT id, email FROM users WHERE email = 'admin@brokerplace.com'");

if ($check->num_rows > 0) {
    $admin = $check->fetch_assoc();
    
    // Create NEW password hash using current PHP version
    $password = 'admin123';
    $new_hash = password_hash($password, PASSWORD_DEFAULT);
    
    // Update the password
    $stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE email = 'admin@brokerplace.com'");
    $stmt->bind_param("s", $new_hash);
    
    if ($stmt->execute()) {
        echo "<h2 style='color:green'>✓ Admin password fixed!</h2>";
        echo "<p><strong>Email:</strong> admin@brokerplace.com</p>";
        echo "<p><strong>Password:</strong> admin123</p>";
        echo "<p><strong>New Hash:</strong> " . $new_hash . "</p>";
        
        // Test the hash immediately
        $test = password_verify('admin123', $new_hash);
        echo "<p><strong>Hash Test:</strong> " . ($test ? '✓ Working' : '✗ Failed') . "</p>";
        
        echo "<hr>";
        echo "<a href='/broker_system/admin/login.php' style='background:#28a745; color:white; padding:10px 20px; text-decoration:none; border-radius:5px;'>Go to Admin Login →</a>";
    } else {
        echo "<h2 style='color:red'>Error: " . $conn->error . "</h2>";
    }
} else {
    // Create new admin
    $full_name = 'Administrator';
    $email = 'admin@brokerplace.com';
    $password = 'admin123';
    $hash = password_hash($password, PASSWORD_DEFAULT);
    
    $stmt = $conn->prepare("INSERT INTO users (full_name, email, password_hash, role, is_verified, email_verified) VALUES (?, ?, ?, 'admin', 1, 1)");
    $stmt->bind_param("sss", $full_name, $email, $hash);
    
    if ($stmt->execute()) {
        echo "<h2 style='color:green'>✓ Admin created!</h2>";
        echo "<p>Email: admin@brokerplace.com</p>";
        echo "<p>Password: admin123</p>";
        echo "<a href='/broker_system/admin/login.php'>Go to Login →</a>";
    } else {
        echo "<h2 style='color:red'>Error: " . $conn->error . "</h2>";
    }
}

$conn->close();
?>